<?php

namespace Aerys;

use Amp\CallableMaker;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Loop;
use Amp\Process\Process as AmpProcess;
use Amp\Promise;
use Amp\Socket\Socket;
use Amp\Socket\Server;
use Psr\Log\LoggerInterface as PsrLogger;

class WatcherProcess extends Process {
    use CallableMaker;

    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    /** @var \Aerys\Console */
    private $console;

    /** @var int Number of workers to create. */
    private $workerCount;

    /** @var string */
    private $ipcServerUri;

    /** @var string Command used to create each worker. */
    private $workerCommand;

    /** @var \Amp\Process\Process[] */
    private $processes;

    /** @var \Amp\Socket\ClientSocket[] */
    private $ipcClients = [];

    /** @var string */
    private $procGarbageWatcher;

    /** @var \Amp\Deferred|null */
    private $stopDeferred;

    /** @var \Amp\Deferred[] */
    private $spawnDeferreds = [];

    private $defunctProcessCount = 0;
    private $expectedFailures = 0;

    private $useSocketTransfer;
    private $addrCtx = [];
    private $serverSockets = [];

    const STOP_SEQUENCE = "\0\0\0\0\n"; // 4 leading NUL-bytes to signal zero sockets if sent early in socket-transfer mode

    public function __construct(PsrLogger $logger) {
        parent::__construct($logger);
        $this->logger = $logger;
        $this->procGarbageWatcher = Loop::repeat(10, $this->callableFromInstanceMethod("collectProcessGarbage"));
        Loop::disable($this->procGarbageWatcher);
        $this->useSocketTransfer = $this->useSocketTransfer();
    }

    private function collectProcessGarbage() {
        foreach ($this->processes as $key => $process) {
            if ($process->isRunning()) {
                continue;
            }

            $this->defunctProcessCount--;
            $process->kill();

            unset($this->processes[$key]);
            if ($this->expectedFailures > 0) {
                $this->expectedFailures--;
                continue;
            }
            if (!$this->stopDeferred) {
                $this->spawn();
            }
        }

        // If we've reaped all known dead processes we can stop checking
        if (empty($this->defunctProcessCount)) {
            Loop::disable($this->procGarbageWatcher);
        }

        if ($this->stopDeferred && empty($this->processes)) {
            Loop::cancel($this->procGarbageWatcher);
            if ($this->stopDeferred) {
                Loop::defer([$this->stopDeferred, "resolve"]);
            }
        }
    }

    protected function doStart(Console $console): \Generator {
        if (yield from $this->checkCommands($console)) {
            return;
        }

        $this->recommendAssertionSetting();
        $this->recommendLogLevel($console);
        $this->stopDeferred = null;
        $this->console = $console;
        $this->workerCount = $this->determineWorkerCount($console);
        $this->ipcServerUri = $this->bindIpcServer();
        $this->workerCommand = $this->generateWorkerCommand($console);
        yield from $this->bindCommandServer((string) $console->getArg("config"));

        $promises = [];
        for ($i = 0; $i < $this->workerCount; $i++) {
            $promises[] = $this->spawn();
        }
        yield \Amp\Promise\any($promises);
    }

    private function checkCommands(Console $console) {
        if ($console->isArgDefined("restart")) {
            yield (new CommandClient((string) $console->getArg("config")))->restart();
            $this->logger->info("Restarting initiated ...");

            return true;
        }

        return false;
    }

    private function bindCommandServer(string $config) {
        $path = CommandClient::socketPath(selectConfigFile($config));

        $unix = in_array("unix", \stream_get_transports(), true);
        if ($unix) {
            $path .= ".sock";
            $socketAddress = "unix://$path";
        } else {
            $socketAddress = "tcp://127.0.0.1:*";
        }

        if (yield \Amp\File\exists($path)) {
            if (is_resource(@stream_socket_client($unix ? $socketAddress : yield \Amp\File\get($path)))) {
                throw new \Error("Aerys is already running, can't start it again");
            } elseif ($unix) {
                yield \Amp\File\unlink($path);
            }
        }

        $commandServer = \Amp\Socket\listen($socketAddress);

        Promise\rethrow(new Coroutine($this->acceptCommand($commandServer, $path)));

        if (!$unix) {
            yield \Amp\File\put($path, $commandServer->getAddress());
        }
    }

    private function acceptCommand(Server $server, string $path): \Generator {
        while ($client = yield $server->accept()) {
            Promise\rethrow(new Coroutine($this->readCommand($client)));
        }

        yield \Amp\File\unlink($path);
    }

    private function readCommand(Socket $client): \Generator {
        $buffer = "";

        while (($chunk = yield $client->read()) !== null) {
            $buffer .= $chunk;

            do {
                if (!isset($length)) {
                    if (!isset($buffer[3])) {
                        break;
                    }
                    $length = unpack("Nlength", substr($buffer, 0, 4))["length"];
                    $buffer = substr($buffer, 4);
                }
                if (!isset($buffer[$length - 1])) {
                    break;
                }
                $message = @\json_decode(substr($buffer, 0, $length), true);
                $buffer = (string) substr($buffer, $length);
                $length = null;

                if (!isset($message["action"])) {
                    continue;
                }

                switch ($message["action"]) {
                    case "restart":
                        $this->restart();
                        break;

                    case "stop":
                        try {
                            yield from $this->stop();
                        } finally {
                            Loop::stop();
                        }
                        return;
                }
            } while (true);
        }

        $client->close();
    }

    protected function recommendAssertionSetting() {
        if (ini_get("zend.assertions") === "1") {
            $this->logger->warning(
                "Running aerys in production with assertions enabled is not recommended; " .
                "disable assertions in php.ini (zend.assertions = -1) for best performance " .
                "or enable debug mode (-d) to hide this warning."
            );
        }
    }

    protected function recommendLogLevel(Console $console) {
        if (!$console->isArgDefined("log")) {
            return;
        }
        $level = strtolower($console->getArg("log"));
        if (!isset(Logger::LEVELS[$level])) {
            return;
        }
        if (Logger::LEVELS[$level] > Logger::WARNING) {
            $this->logger->warning(
                "Running aerys in production with a log level higher than \"warning\" is not " .
                "recommended. Note that internal \"debug\" events are NOT emitted by core " .
                "aerys libs when assertions are disabled."
            );
        }
    }

    private function determineWorkerCount(Console $console) {
        if (!$this->canReusePort()) {
            $this->logger->warning(
                "Environment does not support binding on the same port in multiple processes; " .
                "only one worker will be used."
            );
            return 1;
        }

        $cpuCores = $this->countCpuCores();
        if (!$console->isArgDefined("workers")) {
            return $cpuCores;
        }

        $workers = $console->getArg("workers");
        if ($workers <= 0) {
            $this->logger->warning(
                "Invalid worker count specified; integer >= 0 expected. Using CPU core count ..."
            );
            return $cpuCores;
        }

        if ($workers > $cpuCores) {
            $s = ($cpuCores === 1) ? "" : "s";
            $this->logger->warning(
                "Running aerys with more worker processes than available CPU cores is not " .
                "recommended. Aerys counted {$cpuCores} core{$s}, but you specified {$workers} " .
                "workers. If you know what you're doing you may safely ignore this message, " .
                "but it's usually best to let the server determine the worker count."
            );
        }

        return $workers;
    }

    private function canReusePort() {
        if (stripos(PHP_OS, "WIN") === 0) {
            return true;
        }
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        $ctx = stream_context_create(["socket" => ["so_reuseport" => true]]);
        if (!$sock1 = @stream_socket_server('127.0.0.1:0', $errno, $errstr, $flags, $ctx)) {
            return false;
        }
        $addr = stream_socket_get_name($sock1, false);
        if (!$sock2 = @stream_socket_server($addr, $errno, $errstr, $flags, $ctx)) {
            return false;
        }
        @fclose($sock1);
        @fclose($sock2);

        return true;
    }

    private function countCpuCores() {
        $os = (stripos(PHP_OS, "WIN") === 0) ? "win" : strtolower(PHP_OS);
        switch ($os) {
            case "win":
                $cmd = "wmic cpu get NumberOfCores";
                break;
            case "linux":
                $cmd = "nproc";
                break;
            case "freebsd":
                $cmd = "sysctl -a | grep 'hw.ncpu' | cut -d ':' -f2";
                break;
            case "darwin":
                $cmd = "sysctl -a | grep 'hw.ncpu:' | awk '{ print $2 }'";
                break;
            default:
                $cmd = null;
                break;
        }
        $execResult = $cmd ? shell_exec($cmd) : 1;
        if ($os === 'win') {
            $execResult = explode("\n", $execResult)[1];
        }
        $cores = intval(trim($execResult));

        return $cores;
    }

    private function useSocketTransfer() {
        $os = (stripos(PHP_OS, "WIN") === 0) ? "win" : strtolower(PHP_OS);
        switch ($os) {
            case "darwin":
            case "freebsd":
                // needs socket_export_stream()
                return \extension_loaded("sockets") && PHP_VERSION_ID >= 70007;
        }
        return false;
    }

    private function bindIpcServer() {
        $socketAddress = "127.0.0.1:*";
        $socketTransport = "tcp";

        if (in_array("unix", \stream_get_transports(), true)) {
            $socketAddress = \tempnam(\sys_get_temp_dir(), "aerys-ipc-") . ".sock";
            $socketTransport = "unix";
        }

        $ipcServer = \Amp\Socket\listen("{$socketTransport}://{$socketAddress}");

        Promise\rethrow(new Coroutine($this->accept($ipcServer)));

        return $socketTransport . "://" . $ipcServer->getAddress();
    }

    private function accept(Server $server): \Generator {
        /** @var \Amp\Socket\ClientSocket $client */
        while ($client = yield $server->accept()) {
            // processes are marked as defunct until a socket connection has been established
            if (!--$this->defunctProcessCount) {
                Loop::disable($this->procGarbageWatcher);
            }

            if ($this->stopDeferred) {
                $client->write(self::STOP_SEQUENCE);
                $client->close();
            }

            $this->ipcClients[\spl_object_hash($client)] = $client;

            assert(!empty($this->spawnDeferreds));
            array_shift($this->spawnDeferreds)->resolve();

            Promise\rethrow(new Coroutine($this->read($client)));
        }
    }

    private function read(Socket $client): \Generator {
        $buffer = "";

        while (($chunk = yield $client->read()) !== null) {
            $buffer .= $chunk;

            do {
                if (!isset($length)) {
                    if (!isset($buffer[4])) {
                        break;
                    }
                    $unpacked = unpack("ctype/Nlength", $buffer);
                    $length = $unpacked["length"];
                    $buffer = substr($buffer, 5);
                }
                if (!isset($buffer[$length - 1])) {
                    break;
                }
                $message = substr($buffer, 0, $length);
                $buffer = (string) substr($buffer, $length);
                $length = null;

                if ($unpacked["type"]) {
                    \assert($unpacked["type"] === 1 && $this->useSocketTransfer);
                    $this->parseWorkerAddrCtx($client->getResource(), $message);
                } else {
                    // all type 0 messages received from workers are sent to STDOUT
                    $this->console->output($message);
                }
            } while (true);
        }

        $this->onDeadIpcClient($client);
    }

    private function onDeadIpcClient(Socket $client) {
        $client->close();
        unset($this->ipcClients[\spl_object_hash($client)]);
        $this->defunctProcessCount++;
        Loop::enable($this->procGarbageWatcher);
    }

    /** receives a map of addresses to stream contexts, creates sockets from it and eventually sends them to the worker */
    private function parseWorkerAddrCtx($ipcClient, $message) {
        $addrCtxMap = json_decode($message, true);

        foreach ($addrCtxMap as $address => $context) {
            if (!isset($this->addrCtx[$address])) {
                $this->addrCtx[$address] = $context;

                // do NOT invoke STREAM_SERVER_LISTEN here - we explicitly invoke \socket_listen() in our worker processes
                if (!$socket = stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND, stream_context_create(["socket" => $context]))) {
                    throw new \RuntimeException(sprintf("Failed binding socket on %s: [Err# %s] %s", $address, $errno, $errstr));
                }

                $this->serverSockets[$address] = $socket;
            } elseif ($this->addrCtx[$address] != $context) {
                $this->logger->warning("Context of existing socket for $address differs from new context. Skipping. Current: " . json_encode($this->addrCtx[$address]) . " New: " . json_encode($context));
                unset($addrCtxMap[$address]);
            }
        }

        $sockets = array_intersect_key($this->serverSockets, $addrCtxMap);

        // Number of sockets (pack("N")), then individual sockets
        $gen = (function () use ($ipcClient, &$watcherId, $sockets) {
            $data = pack("N", count($sockets));
            do {
                yield;
                $bytesWritten = \fwrite($ipcClient, $data);
                $data = \substr($data, $bytesWritten);
                if ($bytesWritten === false || ($bytesWritten === 0 && (!\is_resource($ipcClient) || @\feof($ipcClient)))) {
                    Loop::cancel($watcherId);
                }
            } while ($data != "");

            $ipcSock = \socket_import_stream($ipcClient);
            foreach ($sockets as $address => $socket) {
                yield;
                if (!\socket_sendmsg($ipcSock, ["iov" => [$address], "control" => [["level" => \SOL_SOCKET, "type" => \SCM_RIGHTS, "data" => [$socket]]]], 0)) {
                    Loop::cancel($watcherId);
                }
            }

            Loop::cancel($watcherId);
        })();
        $watcherId = Loop::onWritable($ipcClient, function () use ($gen) {
            $gen->next();
        });
    }

    private function generateWorkerCommand(Console $console): string {
        $parts[] = \PHP_BINARY;

        if (false === php_ini_scanned_files()) {
            $parts[] = "-n";
        }

        if ($ini = \get_cfg_var("cfg_file_path")) {
            $parts[] = "-c";
            $parts[] = $ini;
        }

        $parts[] = "-d";
        $parts[] = "zend.assertions=" . ini_get("zend.assertions");

        $parts[] = __DIR__ . "/../bin/aerys-worker";

        $parts[] = "-i";
        $parts[] = $this->ipcServerUri;

        $parts[] = "-c";
        $parts[] = $console->getArg("config");

        $parts[] = "--color";
        $parts[] = $console->getArg("color");

        $parts[] = "-l";
        $parts[] = $console->getArg("log");

        return implode(" ", array_map("escapeshellarg", $parts));
    }

    private function spawn(): Promise {
        $cmd = $this->workerCommand;
        if ($this->useSocketTransfer) {
            $cmd .= " --socket-transfer";
        }

        $options = [
            "html_errors" => "0",
            "display_errors" => "0",
            "log_errors" => "1",
            "bypass_shell" => true,
        ];

        $process = new AmpProcess($cmd, null, [], $options);
        $process->start();

        $process->getStdin()->close();
        $process->getStdout()->close();
        $process->getStderr()->close();

        $this->processes[] = $process;

        // mark processes as potentially defunct until the IPC socket connection has been established
        Loop::enable($this->procGarbageWatcher);
        $this->defunctProcessCount++;

        return ($this->spawnDeferreds[] = new Deferred)->promise();
    }

    public function restart() {
        $this->serverSockets = $this->addrCtx = [];
        $spawn = count($this->ipcClients);
        for ($i = 0; $i < $spawn; $i++) {
            $this->spawn()->onResolve(function () {
                current($this->ipcClients)->end(self::STOP_SEQUENCE);
                next($this->ipcClients);
            });
        }
        $this->expectedFailures += $spawn;
    }

    protected function doStop(): \Generator {
        if (!$this->stopDeferred) {
            $this->stopDeferred = new Deferred;
            foreach ($this->ipcClients as $ipcClient) {
                $ipcClient->end(self::STOP_SEQUENCE);
            }
        }

        yield $this->stopDeferred->promise();
    }
}

<?php

namespace Aerys;

use Amp\CallableMaker;
use Amp\Deferred;
use Amp\Loop;
use Amp\Success;
use Psr\Log\LoggerInterface as PsrLogger;

class WatcherProcess extends Process {
    use CallableMaker;

    private $logger;
    private $console;
    private $workerCount;
    private $ipcServerUri;
    private $workerCommand;
    private $processes;
    private $ipcClients = [];
    private $procGarbageWatcher;
    private $commandServerWatcher;
    private $ipcServerWatcher;
    private $stopDeferred;
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
        foreach ($this->processes as $key => $procHandle) {
            $info = proc_get_status($procHandle);
            if ($info["running"]) {
                continue;
            }
            $this->defunctProcessCount--;
            proc_close($procHandle);
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
            Loop::cancel($this->commandServerWatcher);
            Loop::cancel($this->ipcServerWatcher);
            if ($this->stopDeferred !== true) {
                Loop::defer([$this->stopDeferred, "resolve"]);
            }
            $this->stopDeferred = true;
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
        $path = CommandClient::socketPath(Bootstrapper::selectConfigFile($config));

        $unix = in_array("unix", \stream_get_transports(), true);
        if ($unix) {
            $path .= ".sock";
            $socketAddress = "unix://$path";
        } else {
            $socketAddress = "tcp://127.0.0.1:*";
        }

        if (yield \Amp\file\exists($path)) {
            if (is_resource(@stream_socket_client($unix ? $socketAddress : yield \Amp\file\get($path)))) {
                throw new \RuntimeException("Aerys is already running, can't start it again");
            } elseif ($unix) {
                yield \Amp\file\unlink($path);
            }
        }

        if (!$commandServer = @stream_socket_server($socketAddress, $errno, $errstr)) {
            throw new \RuntimeException(sprintf(
                "Failed binding socket server on $socketAddress: [%d] %s",
                $errno,
                $errstr
            ));
        }

        stream_set_blocking($commandServer, false);
        $this->commandServerWatcher = Loop::onReadable($commandServer, $this->callableFromInstanceMethod("acceptCommand"));

        register_shutdown_function(function () use ($path) {
            @\unlink($path);
        });
        if (!$unix) {
            yield \Amp\file\put($path, stream_socket_get_name($commandServer, $wantPeer = false));
        }
    }

    private function acceptCommand($watcherId, $commandServer) {
        if (!$client = @stream_socket_accept($commandServer, $timeout = 0)) {
            return;
        }
        stream_set_blocking($client, false);
        $parser = $this->commandParser($client);
        $readWatcherId = Loop::onReadable($client, function () use ($parser) { $parser->next(); });
        $parser->send($readWatcherId);
    }

    private function commandParser($client): \Generator {
        $readWatcherId = yield;
        $buffer = "";
        $length = null;

        do {
            yield;
            $data = @fread($client, 8192);
            if ($data == "" && (!is_resource($client) || @feof($client))) {
                Loop::cancel($readWatcherId);
                return;
            }

            $buffer .= $data;
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
                        (new \Amp\Coroutine($this->stop()))->onResolve([Loop::class, "stop"]);
                        break;
                }
            } while (1);
        } while (1);
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

        if (!$ipcServer = @\stream_socket_server("{$socketTransport}://{$socketAddress}", $errno, $errstr)) {
            throw new \RuntimeException(\sprintf(
                "Failed binding socket server on {$socketTransport}://{$socketAddress}: [%d] %s",
                $errno,
                $errstr
            ));
        }

        \stream_set_blocking($ipcServer, false);
        $this->ipcServerWatcher = Loop::onReadable($ipcServer, $this->callableFromInstanceMethod("accept"));

        $resolvedSocketAddress = \stream_socket_get_name($ipcServer, $wantPeer = false);

        return "{$socketTransport}://{$resolvedSocketAddress}";
    }

    private function accept($watcherId, $ipcServer) {
        if (!$ipcClient = @stream_socket_accept($ipcServer, $timeout = 0)) {
            return;
        }

        // processes are marked as defunct until a socket connection has been established
        if (!--$this->defunctProcessCount) {
            Loop::disable($this->procGarbageWatcher);
        }

        if ($this->stopDeferred) {
            @\fwrite($ipcClient, self::STOP_SEQUENCE);
        }

        stream_set_blocking($ipcClient, false);
        $parser = $this->parser($ipcClient);
        $readWatcherId = Loop::onReadable($ipcClient, function () use ($parser) {
            $parser->next();
        });
        $this->ipcClients[$readWatcherId] = $ipcClient;
        $parser->send($readWatcherId);

        assert(!empty($this->spawnDeferreds));
        array_shift($this->spawnDeferreds)->resolve();
    }

    private function parser($ipcClient): \Generator {
        $readWatcherId = yield;
        $buffer = "";
        $length = null;

        do {
            yield;
            $data = @fread($ipcClient, 8192);

            $buffer .= $data;
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

                    $this->parseWorkerAddrCtx($ipcClient, $message);
                } else {
                    // all type 0 messages received from workers are sent to STDOUT
                    $this->console->output($message);
                }
            } while (1);

            if (($data == "" && !is_resource($ipcClient)) || @feof($ipcClient)) {
                $this->onDeadIpcClient($readWatcherId, $ipcClient);
                return;
            }
        } while (1);
    }

    private function onDeadIpcClient(string $readWatcherId, $ipcClient) {
        Loop::cancel($readWatcherId);
        @fclose($ipcClient);
        unset($this->ipcClients[$readWatcherId]);
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

    private function spawn() {
        $cmd = $this->workerCommand;
        if ($this->useSocketTransfer) {
            $cmd .= " --socket-transfer";
        }
        $fds = [["pipe", "r"], ["pipe", "w"], ["pipe", "w"]];
        $options = ["bypass_shell" => true];
        if (!$procHandle = \proc_open($cmd, $fds, $pipes, null, null, $options)) {
            return new \Amp\Failure(new \RuntimeException(
                "Failed spawning worker process"
            ));
        }
        foreach ($pipes as $pipe) {
            @fclose($pipe);
        }
        $this->processes[] = $procHandle;

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
                @\fwrite(current($this->ipcClients), self::STOP_SEQUENCE);
                next($this->ipcClients);
            });
        }
        $this->expectedFailures += $spawn;
    }

    protected function doStop(): \Generator {
        if (!$this->stopDeferred) {
            $this->stopDeferred = new Deferred;
            foreach ($this->ipcClients as $ipcClient) {
                @\fwrite($ipcClient, self::STOP_SEQUENCE);
            }
        }

        yield $this->stopDeferred === true ? new Success : $this->stopDeferred->promise();
    }
}

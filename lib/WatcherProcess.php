<?php

namespace Aerys;

use Amp\{
    Failure,
    Deferred
};

class WatcherProcess extends Process {
    private $logger;
    private $console;
    private $workerCount;
    private $ipcServerUri;
    private $workerCommand;
    private $processes = [];
    private $ipcClients = [];
    private $procGarbageWatcher;
    private $stopPromisor;
    private $defunctProcessCount = 0;

    public function __construct(Logger $logger) {
        parent::__construct($logger);
        $this->logger = $logger;
        $this->procGarbageWatcher = \Amp\repeat(function() {
            $this->collectProcessGarbage();
        }, 100, ["enable" => false]);
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
            if (empty($this->stopPromisor)) {
                $this->spawn();
            }
        }

        // If we've reaped all known dead processes we can stop checking
        if (empty($this->defunctProcessCount)) {
            \Amp\disable($this->procGarbageWatcher);
        }

        if ($this->stopPromisor && empty($this->processes)) {
            \Amp\cancel($this->procGarbageWatcher);
            \Amp\immediately([$this->stopPromisor, "succeed"]);
            $this->stopPromisor = null;
        }
    }

    protected function doStart(Console $console): \Generator {
        $this->recommendAssertionSetting();
        $this->recommendLogLevel($console);
        $this->console = $console;
        $this->workerCount = $this->determineWorkerCount($console);
        $this->ipcServerUri = $this->bindIpcServer();
        $this->workerCommand = $this->generateWorkerCommand($console);

        for ($i=0; $i<$this->workerCount; $i++) {
            yield $this->spawn();
        }
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
        $os = (stripos(PHP_OS, "WIN") === 0) ? "win" : strtolower(trim(shell_exec("uname")));
        switch ($os) {
            case "win":
                $cmd = "wmic cpu get NumberOfCores";
                break;
            case "linux":
                $cmd = "cat /proc/cpuinfo | grep processor | wc -l";
                break;
            case "freebsd":
                $cmd = "sysctl -a | grep 'hw.ncpu' | cut -d ':' -f2";
                break;
            case "darwin":
                $cmd = "sysctl -a | grep 'hw.ncpu:' | awk '{ print $2 }'";
                break;
            default:
                $cmd = NULL;
                break;
        }
        $execResult = $cmd ? shell_exec($cmd) : 1;
        if ($os === 'win') {
            $execResult = explode("\n", $execResult)[1];
        }
        $cores = intval(trim($execResult));

        return $cores;
    }

    private function bindIpcServer() {
        if (!$ipcServer = @stream_socket_server("tcp://127.0.0.1:*", $errno, $errstr)) {
            throw new \RuntimeException(sprintf(
                "Failed binding socket server on tcp://127.0.0.1:*: [%d] %s",
                $errno,
                $errstr
            ));
        }

        stream_set_blocking($ipcServer, false);
        \Amp\onReadable($ipcServer, function(...$args) { $this->accept(...$args); });

        return stream_socket_get_name($ipcServer, $wantPeer = false);
    }

    private function accept($watcherId, $ipcServer) {
        if (!$ipcClient = @stream_socket_accept($ipcServer, $timeout = 0)) {
            return;
        }
        $clientId = (int) $ipcClient;
        $this->ipcClients[$clientId] = $ipcClient;
        stream_set_blocking($ipcClient, false);
        $parser = $this->parser($ipcClient);
        $onReadable = function() use ($parser) { $parser->next(); };
        $readWatcherId = \Amp\onReadable($ipcClient, $onReadable);
        $parser->send($readWatcherId);
    }

    private function parser($ipcClient): \Generator {
        $readWatcherId = yield;
        $buffer = "";
        $length = null;

        do {
            yield;
            $data = @fread($ipcClient, 8192);
            if ($data == "" && (!is_resource($ipcClient) || @feof($ipcClient))) {
                $this->onDeadIpcClient($readWatcherId, $ipcClient);
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
                $message = substr($buffer, 0, $length);
                $buffer = (string) substr($buffer, $length);
                $length = null;

                // all messages received from workers are sent to STDOUT
                $this->console->output($message);

            } while (1);
        } while (1);
    }

    private function onDeadIpcClient(string $readWatcherId, $ipcClient) {
        \Amp\cancel($readWatcherId);
        @fclose($ipcClient);
        unset($this->ipcClients[(int)$ipcClient]);
        $this->defunctProcessCount++;
        \Amp\enable($this->procGarbageWatcher);
    }

    private function generateWorkerCommand(Console $console): string {
        $parts[] = \PHP_BINARY;

        if ($ini = \get_cfg_var("cfg_file_path")) {
            $parts[] = "-c";
            $parts[] = escapeshellarg($ini);
        }

        $parts[] = "-d zend.assertions=" . escapeshellarg(ini_get("zend.assertions"));
        $parts[] = escapeshellarg(__DIR__ . "/../bin/aerys-worker");

        $parts[] = "-i";
        $parts[] = escapeshellarg($this->ipcServerUri);

        if ($console->isArgDefined("config")) {
            $parts[] = "-c";
            $parts[] = escapeshellarg($console->getArg("config"));
        }

        if ($console->isArgDefined("color")) {
            $parts[] = "--color";
            $parts[] = escapeshellarg($console->getArg("color"));
        }

        if ($console->isArgDefined("log")) {
            $parts[] = "-l";
            $parts[] = escapeshellarg($console->getArg("log"));
        }

        return implode(" ", $parts);
    }

    private function spawn() {
        $cmd = $this->workerCommand;
        $fds = [["pipe", "r"], ["pipe", "w"], ["pipe", "w"]];
        $options = ["bypass_shell" => true];
        if (!$procHandle = \proc_open($cmd, $fds, $pipes, null, null, $options)) {
            return new Failure(new \RuntimeException(
                "Failed spawning worker process"
            ));
        }
        foreach ($pipes as $pipe) {
            @fclose($pipe);
        }
        $this->processes[] = $procHandle;
    }

    protected function doStop(): \Generator {
        $this->stopPromisor = new Deferred;
        foreach ($this->ipcClients as $ipcClient) {
            @fwrite($ipcClient, "\n");
        }

        yield $this->stopPromisor->promise();
    }
}

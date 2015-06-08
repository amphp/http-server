<?php

namespace Aerys;

use Amp\{
    Reactor,
    Failure,
    Deferred
};

class WatcherProcess extends Process {
    private $reactor;
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

    public function __construct(Reactor $reactor, Logger $logger) {
        parent::__construct($reactor, $logger);
        $this->reactor = $reactor;
        $this->logger = $logger;
        $this->procGarbageWatcher = $reactor->repeat(function() {
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
            $this->reactor->disable($this->procGarbageWatcher);
        }

        if ($this->stopPromisor && empty($this->processes)) {
            $this->reactor->cancel($this->procGarbageWatcher);
            $this->reactor->immediately([$this->stopPromisor, "succeed"]);
            $this->stopPromisor = null;
        }
    }

    protected function doStart(Console $console): \Generator {
        $this->console = $console;
        $this->workerCount = $this->determineWorkerCount($console);
        $this->ipcServerUri = $this->bindIpcServer();
        $this->workerCommand = $this->generateWorkerCommand($console);

        for ($i=0; $i<$this->workerCount; $i++) {
            yield $this->spawn();
        }
    }

    private function determineWorkerCount(Console $console) {
        if (!$this->canReusePort()) {
            return 1;
        }
        if ($workers = $console->getArg("workers")) {
            return $workers;
        }

        return $this->countCpuCores();
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
                "Failed binding socket server on %s: [%d] %s",
                $uri,
                $errno,
                $errstr
            ));
        }

        stream_set_blocking($ipcServer, false);
        $this->reactor->onReadable($ipcServer, function(...$args) { $this->accept(...$args); });

        return stream_socket_get_name($ipcServer, $wantPeer = false);
    }

    private function accept($reactor, $watcherId, $ipcServer) {
        if (!$ipcClient = @stream_socket_accept($ipcServer, $timeout = 0)) {
            return;
        }
        $clientId = (int) $ipcClient;
        $this->ipcClients[$clientId] = $ipcClient;
        stream_set_blocking($ipcClient, false);
        $parser = $this->parser($ipcClient);
        $onReadable = function() use ($parser) { $parser->next(); };
        $readWatcherId = $this->reactor->onReadable($ipcClient, $onReadable);
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
        $this->reactor->cancel($readWatcherId);
        @fclose($ipcClient);
        unset($this->ipcClients[(int)$ipcClient]);
        $this->defunctProcessCount++;
        $this->reactor->enable($this->procGarbageWatcher);
    }

    private function generateWorkerCommand(Console $console): string {
        $parts[] = \PHP_BINARY;
        if ($ini = \get_cfg_var("cfg_file_path")) {
            $parts[] = "-c \"{$ini}\"";
        }
        $parts[] = "-d zend.assertions=" . ini_get("zend.assertions");
        $parts[] = __DIR__ . "/../bin/aerys-worker";
        $parts[] = "-i {$this->ipcServerUri}";
        if ($console->isArgDefined("config")) {
            $parts[] = "-c " . $console->getArg("config");
        }
        if ($console->isArgDefined("color")) {
            $parts[] = "--color " . $console->getArg("color");
        }
        if ($console->isArgDefined("log")) {
            $parts[] = "-l " . $console->getArg("log");
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

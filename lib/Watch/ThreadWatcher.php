<?php

namespace Aerys\Watch;

use Alert\Reactor,
    Alert\ReactorFactory,
    Aerys\Start\BinOptions,
    Aerys\Start\Bootstrapper,
    Aerys\Start\StartException,
    Aerys\HostBinder;

class ThreadWatcher implements ServerWatcher {
    use CpuCounter;

    private $reactor;
    private $hostBinder;
    private $configFile;
    private $ipcPort;
    private $workers;
    private $threads;
    private $servers = [];
    private $threadReflection;

    public function __construct(Reactor $reactor = NULL, HostBinder $hostBinder = NULL) {
        $this->reactor = $reactor ?: (new ReactorFactory)->select();
        $this->hostBinder = $hostBinder ?: new HostBinder;
        $this->threads = new \SplObjectStorage;
        $this->threadReflection = new \ReflectionClass('Aerys\Watch\ThreadWorker');
    }

    public function watch(BinOptions $binOptions) {
        $this->configFile = $binOptions->getConfig();

        $thread = new ThreadConfigTry($this->configFile);
        $thread->start();
        $thread->join();

        if ($thread->error) {
            throw new StartException($thread->error);
        }

        $this->hostBinder->setSocketBacklogSize($thread->options['socketBacklogSize']);

        $this->workers = $binOptions->getWorkers() ?: $this->countCpuCores();
        $this->ipcPort = $this->startIpcServer($binOptions);
        $this->servers = $this->hostBinder->bindAddresses($thread->bindTo, $this->servers);

        foreach ($thread->bindTo as $addr) {
            $addr = substr(str_replace('0.0.0.0', '*', $addr), 6);
            printf("Listening for HTTP traffic on %s ...\n", $addr);
        }

        for ($i=0; $i < $this->workers; $i++) {
            $this->spawn();
        }

        $this->reactor->run();
    }

    private function startIpcServer(BinOptions $binOptions) {
        $ipcPort = $binOptions->getBackend() ?: '*';
        $ipcServer = stream_socket_server("tcp://127.0.0.1:{$ipcPort}", $errno, $errstr);

        if (!$ipcServer) {
            throw new \RuntimeException(
                sprintf("Failed binding IPC server on %s: [%d] %s", $uri, $errno, $errstr)
            );
        }

        stream_set_blocking($ipcServer, FALSE);

        $this->reactor->onReadable($ipcServer, function() use ($ipcServer) {
            $this->accept($ipcServer);
        });

        $ipcName = stream_socket_get_name($ipcServer, $wantPeer = FALSE);
        $ipcPort = substr($ipcName, strrpos($ipcName, ':') + 1);

        return $ipcPort;
    }

    private function accept($ipcServer) {
        while ($ipcClient = @stream_socket_accept($ipcServer, $timeout = 0)) {
            stream_set_blocking($ipcClient, FALSE);
            $this->reactor->onReadable($ipcClient, function($watcherId, $ipcClient) {
                $this->onReadableClient($watcherId, $ipcClient);
            });
        }
    }

    private function onReadableClient($watcherId, $ipcClient) {
        $this->reactor->cancel($watcherId);
        @fclose($ipcClient);
        $this->spawn();
        $this->collect();
    }

    public function spawn() {
        $args = $this->servers;
        array_unshift($args, $this->configFile, $this->ipcPort);
        $args = array_values($args);

        $thread = $this->threadReflection->newInstanceArgs($args);
        $thread->start();
        $this->threads->attach($thread);
    }

    private function collect() {
        foreach ($this->threads as $thread) {
            if ($thread->isTerminated()) {
                $this->threads->detach($thread);
            }
        }
    }
}

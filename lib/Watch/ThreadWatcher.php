<?php

namespace Aerys\Watch;

use Alert\Reactor,
    Alert\ReactorFactory,
    Aerys\BinOptions,
    Aerys\Bootstrapper,
    Aerys\StartException,
    Aerys\HostBinder;

class ThreadWatcher implements ServerWatcher {
    use CpuCounter;

    private $reactor;
    private $hostBinder;
    private $threads;
    private $threadReflection;
    private $debug;
    private $config;
    private $ipcPort;
    private $workers;
    private $servers = [];

    public function __construct(Reactor $reactor = NULL, HostBinder $hostBinder = NULL) {
        $this->reactor = $reactor ?: (new ReactorFactory)->select();
        $this->hostBinder = $hostBinder ?: new HostBinder;
        $this->threads = new \SplObjectStorage;
        $this->threadReflection = new \ReflectionClass('Aerys\Watch\ThreadWorker');
    }

    public function watch(BinOptions $binOptions) {
        $this->debug = $binOptions->getDebug();
        $this->config = $binOptions->getConfig();

        $thread = new ThreadConfigTry($this->debug, $this->config);
        $thread->start();
        $thread->join();

        list($bindTo, $options, $error) = $thread->getBootResultStruct();

        if ($error) {
            throw new StartException($error);
        }

        $this->hostBinder->setSocketBacklogSize($options['socketBacklogSize']);

        $this->workers = $binOptions->getWorkers() ?: $this->countCpuCores();
        $this->ipcPort = $this->startIpcServer($binOptions);
        $this->servers = $this->hostBinder->bindAddresses($bindTo, $this->servers);

        foreach ($bindTo as $addr) {
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
        array_unshift($args, $this->debug, $this->config, $this->ipcPort);
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

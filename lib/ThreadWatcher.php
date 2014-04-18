<?php

declare(ticks = 1);

namespace Aerys;

use Alert\Reactor, Alert\ReactorFactory;

class ThreadWatcher implements ServerWatcher {
    use CpuCounter;

    private $reactor;
    private $hostBinder;
    private $threads;
    private $threadReflection;
    private $debug;
    private $config;
    private $ipcUri;
    private $workerCount;
    private $servers = [];
    private $ipcClients = [];
    private $isStopping = FALSE;
    private $isReloading = FALSE;

    public function __construct(Reactor $reactor = NULL, HostBinder $hostBinder = NULL) {
        $this->reactor = $reactor ?: (new ReactorFactory)->select();
        $this->hostBinder = $hostBinder ?: new HostBinder;
        $this->threads = new \SplObjectStorage;
        $this->threadReflection = new \ReflectionClass('Aerys\ThreadWorker');
    }

    public function watch(BinOptions $binOptions) {
        $this->debug = $binOptions->getDebug();
        $this->config = $binOptions->getConfig();

        $thread = new TryThreadConfig($this->debug, $this->config, $bind = TRUE);
        $thread->start();
        $thread->join();

        list($bindTo, $options, $error) = $thread->getBootResultStruct();

        if ($error) {
            throw new BootException($error);
        }

        $this->startIpcServer();

        $this->hostBinder->setSocketBacklogSize($options['socketBacklogSize']);
        $this->workerCount = $binOptions->getWorkers() ?: $this->countCpuCores();
        $this->servers = $this->hostBinder->bindAddresses($bindTo, $this->servers);

        if (extension_loaded('pcntl')) {
            $stopCallback = [$this, 'stop'];
            pcntl_signal(SIGINT, $stopCallback);
            pcntl_signal(SIGTERM, $stopCallback);
        }

        foreach ($bindTo as $addr) {
            $addr = substr(str_replace('0.0.0.0', '*', $addr), 6);
            printf("Listening for HTTP traffic on %s ...\n", $addr);
        }

        for ($i=0; $i < $this->workerCount; $i++) {
            $this->spawn();
        }

        $this->reactor->run();

        foreach ($this->threads as $thread) {
            $thread->kill();
        }
    }

    private function startIpcServer() {
        if ($ipcServer = stream_socket_server("tcp://127.0.0.1:*", $errno, $errstr)) {
            stream_set_blocking($ipcServer, FALSE);
            $this->ipcUri = stream_socket_get_name($ipcServer, $wantPeer = FALSE);
            $this->reactor->onReadable($ipcServer, function() use ($ipcServer) {
                $this->accept($ipcServer);
            });
        } else {
            throw new \RuntimeException(
                sprintf("Failed binding socket server on %s: [%d] %s", $uri, $errno, $errstr)
            );
        }
    }

    private function accept($ipcServer) {
        while ($ipcClient = @stream_socket_accept($ipcServer, $timeout = 0)) {
            $clientId = (int) $ipcClient;
            stream_set_blocking($ipcClient, FALSE);
            $this->ipcClients[$clientId] = $ipcClient;
            $this->reactor->onReadable($ipcClient, function($watcherId, $ipcClient) {
                $clientId = (int) $ipcClient;
                $this->unloadIpcClient($clientId, $watcherId, $ipcClient);
            });
        }
    }

    private function unloadIpcClient($clientId, $watcherId, $ipcClient) {
        unset($this->ipcClients[$clientId]);
        $this->reactor->cancel($watcherId);
        if (is_resource($ipcClient)) {
            @fclose($ipcClient);
        }

        $this->collect();

        if ($this->isStopping && empty($this->ipcClients)) {
            $this->isStopping = FALSE;
            $this->reactor->stop();
        } elseif (!($this->isStopping || $this->reloading)) {
            for ($i=count($this->ipcClients); $i<$this->workerCount; $i++) {
                $this->spawn();
            }
        } elseif ($this->reloading && count($this->ipcClients) === $this->workerCount) {
            $this->reloading = FALSE;
        }
    }

    private function collect() {
        foreach ($this->threads as $thread) {
            if ($thread->isTerminated()) {
                $this->threads->detach($thread);
            }
        }
    }

    public function stop() {
        if (!$this->isStopping) {
            $this->isStopping = TRUE;
            $this->notifyWorkers();
        }
    }

    private function notifyWorkers() {
        foreach ($this->ipcClients as $clientId => $clientStruct) {
            list($watcherId, $ipcClient) = $clientStruct;
            @stream_set_blocking($ipcClient, TRUE);
            if (!@fwrite($ipcClient, ".")) {
                $this->unloadIpcClient($clientId, $watcherId, $ipcClient);
            } else {
                stream_set_blocking($ipcClient, FALSE);
            }
        }
    }

    public function reload() {
        if (!($this->reloading || $this->isStopping)) {
            $this->reloading = TRUE;
            $this->notifyWorkers();
            for ($i=0; $i < $this->workerCount; $i++) {
                $this->spawn();
            }
            $this->outputListenAddresses();
        }
    }

    private function spawn() {
        $args = $this->servers;
        array_unshift($args, $this->debug, $this->config, $this->ipcUri);
        $args = array_values($args);

        $thread = $this->threadReflection->newInstanceArgs($args);
        $thread->start();
        $this->threads->attach($thread);
    }
}

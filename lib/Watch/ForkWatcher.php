<?php

declare(ticks = 1);

namespace Aerys\Watch;

use Alert\Reactor,
    Alert\ReactorFactory,
    Aerys\HostBinder,
    Aerys\Bootstrapper,
    Aerys\BootException;

class ForkWatcher implements ServerWatcher {
    use CpuCounter;

    private $reactor;
    private $hostBinder;
    private $debug;
    private $config;
    private $ipcUri;
    private $options;
    private $hostAddrs;
    private $workerCount;
    private $ipcClients = [];
    private $serverSocks = [];
    private $isStopping = FALSE;
    private $isReloading = FALSE;

    public function __construct(Reactor $reactor = NULL, HostBinder $hb = NULL) {
        $this->reactor = $reactor ?: (new ReactorFactory)->select();
        $this->hostBinder = $hb ?: new HostBinder;
    }

    public function watch(BinOptions $binOptions) {
        $this->debug = $binOptions->getDebug();
        $this->validateConfig($binOptions->getConfig(), $isReload = FALSE);
        $this->startIpcServer();
        $this->bindServerSocks();

        $stopCallback = [$this, 'stop'];
        pcntl_signal(SIGCHLD, SIG_IGN);
        pcntl_signal(SIGINT, $stopCallback);
        pcntl_signal(SIGTERM, $stopCallback);

        $this->workerCount = $binOptions->getWorkers() ?: $this->countCpuCores();
        for ($i=0; $i < $this->workerCount; $i++) {
            $this->spawn();
        }

        $this->outputListenAddresses();
        $this->reactor->run();
    }

    private function validateConfig($config, $isReload) {
        $cmd = $this->buildConfigTestCmd($config, $isReload);
        exec($cmd, $output);
        $output = implode($output, "\n");
        $data = @unserialize($output);

        if (empty($data)) {
            throw new BootException($output);
        } elseif ($data['error']) {
            throw new BootException($data['error_msg']);
        } else {
            $this->config = $config;
            $this->hostAddrs = $data['hosts'];
            $this->options = $data['options'];
        }
    }

    private function buildConfigTestCmd($config, $isReload) {
        $cmd[] = PHP_BINARY;
        if ($ini = get_cfg_var('cfg_file_path')) {
            $cmd[] = "-c $ini";
        }
        $cmd[] = __DIR__ . "/../../src/config-test.php";
        $cmd[] = "--config {$config}";
        if ($this->debug) {
            $cmd[] = "--debug";
        }
        if (!$isReload) {
            $cmd[] = "--bind";
        }

        return implode(' ', $cmd);
    }

    private function bindServerSocks() {
        $backlog = $this->options['socketBacklogSize'];
        $this->hostBinder->setSocketBacklogSize($backlog);
        $this->serverSocks = $this->hostBinder->bindAddresses($this->hostAddrs, $this->serverSocks);
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

        if ($this->isStopping && empty($this->ipcClients)) {
            $this->isStopping = FALSE;
            $this->reactor->stop();
            return;
        } elseif (!($this->isStopping || $this->isReloading)) {
            for ($i=count($this->ipcClients); $i<$this->workerCount; $i++) {
                $this->spawn();
            }
        } elseif ($this->isReloading && count($this->ipcClients) === $this->workerCount) {
            $this->isReloading = FALSE;
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
        if (!($this->isReloading || $this->isStopping)) {
            $this->isReloading = TRUE;
            $this->notifyWorkers();
            for ($i=0; $i < $this->workerCount; $i++) {
                $this->spawn();
            }
            $this->outputListenAddresses();
        }
    }

    private function outputListenAddresses() {
        foreach ($this->hostAddrs as $address) {
            $address = substr(str_replace('0.0.0.0', '*', $address), 6);
            printf("Listening for HTTP traffic on %s ...\n", $address);
        }
    }

    private function spawn() {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $this->runChildFork();
        } elseif (!$pid) {
            throw new \RuntimeException(
                'Failed forking worker process'
            );
        }
    }

    private function runChildFork() {
        $this->reactor->stop();
        $this->reactor = NULL;
        $this->hostBinder = NULL;

        list($reactor, $server) = (new Bootstrapper)->boot($this->config, $options = [
            'bind' => TRUE,
            'socks' => $this->serverSocks,
            'debug' => $this->debug,
        ]);

        (new ProcWorker($reactor, $server))
            ->start($this->ipcUri)
            ->registerSignals()
            ->registerShutdown()
            ->run()
        ;

        exit;
    }
}

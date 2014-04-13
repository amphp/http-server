<?php

namespace Aerys\Watch;

use Aerys\HostBinder,
    Aerys\Bootstrapper,
    Aerys\BinOptions,
    Aerys\StartException;

class ForkWatcher implements ServerWatcher {
    use CpuCounter;

    private $ipcBroker;
    private $bootstrapper;
    private $hostBinder;
    private $configPath;
    private $binOptions;
    private $backendUri;
    private $socketPool = [];
    private $isShuttingDown = FALSE;
    private $connectMsg = <<<EOT
---------------------------------------------------------
Aerys Control Console
---------------------------------------------------------
reload {%OPTIONAL_PATH%}   Perform a "hot" server upgrade
stop/shutdown              Gracefully shutdown the server
close/quit/exit            Close this console session
---------------------------------------------------------

EOT;

    public function __construct(
        IpcBroker $ipcBroker = NULL,
        Bootstrapper $bootstrapper = NULL,
        HostBinder $hostBinder = NULL
    ) {
        $this->ipcBroker = $ipcBroker ?: new IpcBroker;
        $this->bootstrapper = $bootstrapper ?: new Bootstrapper;
        $this->hostBinder = $hostBinder ?: new HostBinder;

        $this->ipcBroker->setIpcEventCallbacks(IpcBroker::BACKEND, [
            'onClientClose' => function() {
                if (!$this->isShuttingDown) {
                    $this->fork();
                }
            }
        ]);

        $this->ipcBroker->setIpcEventCallbacks(IpcBroker::FRONTEND, [
            'onClient' => function($clientId) {
                $this->ipcSend(IpcBroker::FRONTEND, $clientId, $this->connectMsg);
            },
            'onClientSignal' => function($clientId, $signal) {
                $this->receiveFrontendIpcSignal($clientId, $signal);
            }
        ]);
    }

    /**
     * Monitor worker forks while listening for and reacting to remote control commands
     *
     * @param \Aerys\Framework\BinOptions
     * @return void
     */
    public function watch(BinOptions $binOptions) {
        $this->setBinOptions($binOptions);
        $this->bindSocketsFromConfig();
        $this->initializeIpc();
        $this->forkWorkers();
        $this->ipcBroker->run();
    }

    private function setBinOptions(BinOptions $binOptions) {
        $this->binOptions = $binOptions;
        $this->configPath = $binOptions->getConfig();
    }

    private function bindSocketsFromConfig() {
        $addresses = $this->configPath
            ? $this->generateAddressesFromConfigFile()
            : $this->generateAddressesFromDocroot();

        $this->socketPool = $this->hostBinder->bindAddresses($addresses, $this->socketPool);

        foreach ($addresses as $address) {
            $address = substr(str_replace('0.0.0.0', '*', $address), 6);
            printf("Listening for HTTP traffic on %s ...\n", $address);
        }
    }

    private function generateAddressesFromDocroot() {
        $ip = $this->binOptions->getIp() ?: '0.0.0.0';
        $port = $this->binOptions->getPort() ?: '80';
        $bindAddress = sprintf("tcp://%s:%d", $ip, $port);

        return [$bindAddress];
    }

    private function generateAddressesFromConfigFile() {
        $configPath = escapeshellarg($this->configPath);
        $cmd = $this->generateConfigValidationCommand($configPath);
        exec($cmd, $output, $exitCode);

        $output = implode($output, "\n");
        $data = @unserialize($output);

        if (empty($data)) {
            throw new StartException($output);
        }

        if ($data['error']) {
            throw new StartException($data['error_msg']);
        }

        $addresses = array_unique($data['hosts']);
        $socketBacklogSize = $data['options']['socketBacklogSize'];
        $this->hostBinder->setSocketBacklogSize($socketBacklogSize);
        $this->socketPool = $this->hostBinder->bindAddresses($addresses, $this->socketPool);

        return $addresses;
    }

    private function generateConfigValidationCommand($appConfigPath) {
        $parts = [];
        $parts[] = PHP_BINARY;
        if ($ini = get_cfg_var('cfg_file_path')) {
            $parts[] = "-c $ini";
        }
        $parts[] = __DIR__ . "/../../src/config-test.php -c";
        $parts[] = $appConfigPath;

        return implode(' ', $parts);
    }

    private function initializeIpc() {
        if ($control = $this->binOptions->getControl()) {
            $this->ipcBroker->setFrontendPort($control);
        }

        $this->ipcBroker->start();
    }

    private function ipcSend($target, $clientId, $data) {
        $this->ipcBroker->operate($target, IpcBroker::OP_SEND, [$clientId, $data]);
    }

    private function ipcBroadcast($target, $data) {
        $this->ipcBroker->operate($target, IpcBroker::OP_BROADCAST, [$data]);
    }

    private function ipcClose($target, $clientId) {
        $this->ipcBroker->operate($target, IpcBroker::OP_CLOSE, [$clientId]);
    }

    private function ipcCloseAll($target) {
        $this->ipcBroker->operate($target, IpcBroker::OP_CLOSE_ALL);
    }

    private function receiveFrontendIpcSignal($clientId, $signal) {
        printf("IPC: %s\n", $signal);

        if (strcasecmp($signal, 'reload') === 0) {
            $this->reloadFromExistingConfig($clientId);
        } elseif (stripos($signal, 'reload ') === 0) {
            $configPath = substr($signal, 7);
            $this->reloadFromConfigPath($clientId, $configPath);
        } elseif (strcasecmp($signal, 'stop') === 0
            || strcasecmp($signal, 'shutdown') === 0
        ) {
            $this->isShuttingDown = TRUE;
            $this->ipcBroker->shutdown($onComplete = function() {
                exit(0);
            });
        } elseif (strcasecmp($signal, 'close') === 0
            || strcasecmp($signal, 'quit') === 0
            || strcasecmp($signal, 'exit') === 0
        ) {
            $this->ipcSend(IpcBroker::FRONTEND, $clientId, '> Goodbye!');
            $this->ipcClose(IpcBroker::FRONTEND, $clientId);
        } else {
            $data = sprintf("> Unknown signal: %s", $signal);
            $this->ipcSend(IpcBroker::FRONTEND, $clientId, $data);
        }
    }

    private function reloadFromExistingConfig($clientId) {
        if ($this->configPath === NULL) {
            $data = "> Nothing to reload: serving static docroot";
            $this->ipcSend(IpcBroker::FRONTEND, $clientId, $data);
        } else {
            $this->reloadFromConfigPath($clientId, $this->configPath);
        }
    }

    private function reloadFromConfigPath($clientId, $configPath) {
        try {
            $oldBinOptions = $this->binOptions;
            $oldConfigPath = $this->configPath;
            $newBinOptions = (new BinOptions)->loadOptions(['config' => $configPath]);
            $this->setBinOptions($newBinOptions);
            $this->bindSocketsFromConfig();
            $this->ipcBroadcast(IpcBroker::BACKEND, 'stop');
            $data = sprintf("> Config reloaded (%s)", $configPath);
            $this->ipcSend(IpcBroker::FRONTEND, $clientId, $data);
        } catch (StartException $e) {
            $this->binOptions = $oldBinOptions;
            $this->configPath = $oldConfigPath;
            $data = sprintf("> Reload failed :(\n%s", $e->getMessage());
            $this->ipcSend(IpcBroker::FRONTEND, $clientId, $data);
        }
    }

    private function forkWorkers() {
        $workerCount = $this->binOptions->getWorkers() ?: $this->countCpuCores();
        $this->ipcBroker->pause();
        for ($i=0; $i < $workerCount; $i++) {
            $this->fork();
        }
    }

    private function fork() {
        $pid = pcntl_fork();

        if ($pid > 0) {
            $this->ipcBroker->resume();
        } elseif ($pid === 0) {
            $this->runChildFork();
        } else {
            throw new \RuntimeException(
                'Failed forking worker process'
            );
        }
    }

    private function runChildFork() {
        $backendUri = $this->ipcBroker->getBackendUri();
        $this->ipcBroker = NULL;
        list($reactor, $server, $hosts) = $this->bootstrapper->boot($this->binOptions);
        $server->start($hosts, $this->socketPool);
        $worker = new IpcWorker($reactor, $server);
        $worker->start($backendUri);
        $worker->run();
    }

    public function __destruct() {
        $this->clearBackendUnixSocketFile();
    }

    private function clearBackendUnixSocketFile() {
        if ($this->ipcBroker && $this->ipcBroker->hasUnixBackendSocket()) {
            $udgFile = substr($this->ipcBroker->getBackendUri(), 7);
            @unlink($udgFile);
        }
    }

}

<?php

namespace Aerys\Watch;

use Aerys\Start\Bootstrapper;

class ThreadWorker extends \Thread {
    private $configFile;
    private $ipcPort;
    private $fatals;
    private $sockPrefix = '__sock_';

    public function __construct($configFile, $ipcPort /*, $socket1, $socket2, ... $socketN*/) {
        $this->configFile = $configFile;
        $this->ipcPort = $ipcPort;

        $argv = func_get_args();
        unset($argv[0], $argv[1]);
        $argv = array_values($argv);

        foreach ($argv as $sock) {
            $name = $this->sockPrefix . base64_encode(stream_socket_get_name($sock, FALSE));
            $this->{$name} = $sock;
        }

        $this->fatals = [
            E_ERROR,
            E_PARSE,
            E_USER_ERROR,
            E_CORE_ERROR,
            E_CORE_WARNING,
            E_COMPILE_ERROR,
            E_COMPILE_WARNING
        ];
    }

    public function run() {
        require __DIR__ . '/../../src/bootstrap.php';

        $ipcUri = "tcp://127.0.0.1:{$this->ipcPort}";
        $ipcSock = stream_socket_client($ipcUri, $errno, $errstr);

        if (!$ipcSock) {
            throw new \RuntimeException(
                sprintf('Failed connecting to IPC server %s: [%d] %s', $ipcUri, $errno, $errstr)
            );
        }
        stream_set_blocking($ipcSock, FALSE);

        list($reactor, $server, $hosts) = (new Bootstrapper)->buildFromFile($this->configFile);

        $reactor->onReadable($ipcSock, function() use ($server) {
            $server->stop()->onComplete(function() { exit; });
        });

        register_shutdown_function(function() use ($server) {
            $error = error_get_last();
            if ($error && in_array($error['type'], $this->fatals)) {
                extract($error);
                printf("%s in %s on line %d\n", $message, $file, $line);
                $server->stop()->onComplete(function() { exit; });
            }
        });

        $server->start($hosts, $this->getComposedServerSocks());
        $reactor->run();
    }

    private function getComposedServerSocks() {
        $sockets = [];
        $sockPrefix = $this->sockPrefix;
        $sockPrefixLen = strlen($sockPrefix);
        $properties = get_object_vars($this);

        foreach ($properties as $property => $value) {
            if (strpos($property, $sockPrefix) === 0) {
                $name = 'tcp://' . base64_decode(substr($property, $sockPrefixLen));
                $sockets[$name] = $value;
            }
        }

        return $sockets;
    }
}

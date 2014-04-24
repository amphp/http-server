<?php

namespace Aerys;

class WorkerThread extends \Thread {
    private $debug;
    private $config;
    private $ipcUri;
    private $sockPrefix = '__sock_';

    public function __construct($debug, $config, $ipcUri /*, $sock1, $sock2, ... $sockN*/) {
        $this->debug = $debug;
        $this->config = $config;
        $this->ipcUri = $ipcUri;

        $argv = func_get_args();
        unset($argv[0], $argv[1], $argv[2]);
        $argv = array_values($argv);
        foreach ($argv as $sock) {
            $name = $this->sockPrefix . base64_encode(stream_socket_get_name($sock, FALSE));
            $this->{$name} = $sock;
        }
    }

    public function run() {
        require __DIR__ . '/../src/bootstrap.php';

        if (!$ipcSock = stream_socket_client($this->ipcUri, $errno, $errstr)) {
            throw new \RuntimeException(
                sprintf('Failed connecting to IPC server %s: [%d] %s', $ipcUri, $errno, $errstr)
            );
        }
        stream_set_blocking($ipcSock, FALSE);

        list($reactor, $server) = (new Bootstrapper)->boot($this->config, $opt = [
            'bind'  => TRUE,
            'debug' => $this->debug,
            'socks' => $this->getComposedServerSocks(),
        ]);

        $reactor->onReadable($ipcSock, function() use ($server) {
            $server->stop()->onComplete(function() { exit(0); });
        });

        register_shutdown_function(function() use ($server) {
            $error = error_get_last();
            $fatals = [
                E_ERROR,
                E_PARSE,
                E_USER_ERROR,
                E_CORE_ERROR,
                E_CORE_WARNING,
                E_COMPILE_ERROR,
                E_COMPILE_WARNING
            ];
            if ($error && in_array($error['type'], $fatals)) {
                extract($error);
                printf("%s in %s on line %d\n", $message, $file, $line);
                $server->stop()->onComplete(function() { exit(1); });
            }
        });

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

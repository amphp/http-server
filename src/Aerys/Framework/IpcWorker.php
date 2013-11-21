<?php

namespace Aerys\Framework;

use Alert\Reactor,
    Aerys\Server;

/**
 * The worker listens for and reacts to commands on its IPC socket connection. If the worker
 * receives a "stop" command from the IPC server or if the socket connection goes away the worker
 * will perform a graceful shutdown and exit. The worker will also gracefully shutdown in the event
 * of a fatal application error.
 */
class IpcWorker {

    private $reactor;
    private $server;
    private $hosts;
    private $ipcSocket;
    private $ipcWatcher;
    private $isStopping = FALSE;

    function __construct(Reactor $reactor, Server $server) {
        $this->reactor = $reactor;
        $this->server = $server;
    }

    /**
     * Run the Aerys server and listen for IPC commands
     *
     * Once invoked, this function will run the event reactor and assume program flow control.
     *
     * @param string $ipcUri The backend IPC server's URI
     * @return void
     */
    function start($ipcUri) {
        $this->connectToIpcServer($ipcUri);
        register_shutdown_function([$this, 'onShutdown']);
    }

    function run() {
        $this->reactor->run();
    }

    private function connectToIpcServer($ipcUri) {
        if ($socket = @stream_socket_client($ipcUri, $errno, $errstr)) {
            stream_set_blocking($socket, FALSE);
            $this->ipcSocket = $socket;
            $this->ipcWatcher = $this->reactor->onReadable($socket, function() {
                $this->onReadableIpcSocket();
            });
        } else {
            throw new \RuntimeException(
                sprintf('Failed connecting to IPC server %s: [Err# %d] %s', $ipcUri, $errno, $errstr)
            );
        }
    }

    private function onReadableIpcSocket() {
        $signal = @fgets($this->ipcSocket);
        if ($signal) {
            $this->receiveBackendIpcSignal($signal);
        } elseif (!is_resource($this->ipcSocket) || @feof($this->ipcSocket)) {
            $this->receiveBackendIpcSignal($signal = 'stop');
        }
    }

    /**
     * @TODO accept timeout integer values from the "stop" signal
     */
    private function receiveBackendIpcSignal($signal) {
        list($signal, $arg) = $this->normalizeSignal($signal);
        switch ($signal) {
            case 'stop':
                $this->stop($arg);
                break;
            default:
            // "stop" is the only signal we understand; all others are ignored.
        }
    }

    private function normalizeSignal($signal) {
        $signal = trim(strtolower($signal));

        return strstr($signal, ' ')
            ? explode(' ', $signal, 2)
            : [$signal, $arg = NULL];
    }

    private function stop($timeout = -1) {
        if (!$this->isStopping) {
            $this->isStopping = TRUE;
            $this->reactor->cancel($this->ipcWatcher);
            @fclose($this->ipcSocket);
            $timeout = ($timeout === NULL) ? -1 : (int) $timeout;
            $this->server->stop($timeout);

            exit(0);
        }
    }

    /**
     * Gracefully shutdown the server in the event of a fatal application error
     *
     * If a fatal error occurs while the server is already in the process of stopping the only thing
     * we can do is log the error and shutdown immediately.
     *
     * @return void
     */
    function onShutdown() {
        $fatals = [E_ERROR, E_PARSE, E_USER_ERROR, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING];
        $lastError = error_get_last();

        if ($lastError && in_array($lastError['type'], $fatals)) {
            extract($lastError);
            $errorMsg = sprintf("%s in %s on line %d", $message, $file, $line);
            $this->server->logError($errorMsg);
            $this->stop();
        }
    }

}

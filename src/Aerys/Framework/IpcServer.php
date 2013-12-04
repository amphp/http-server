<?php

namespace Aerys\Framework;

use Alert\Reactor;

class IpcServer implements \Countable {

    private $reactor;
    private $uri;
    private $socket;
    private $acceptWatcher;
    private $onClient;
    private $onSignal;
    private $onClose;
    private $onShutdown;
    private $clients = [];
    private $isShuttingDown = FALSE;

    /**
     * Retrieve the server socket URI
     *
     * @return string
     */
    function getUri() {
        return $this->uri;
    }

    /**
     *
     */
    function setCallback($eventName, callable $callback) {
        switch (strtolower($eventName)) {
            case 'onclient':
                $this->onClient = $callback;
                break;
            case 'onclientsignal':
                $this->onSignal = $callback;
                break;
            case 'onclientclose':
                $this->onClose = $callback;
                break;
            default:
                throw new \DomainException(
                    sprintf('Unkown IPC event name: %s', $eventName)
                );
        }

        return $this;
    }

    /**
     * Start the server
     *
     * @throws \RuntimeException On socket bind failure
     * @return string Returns the URI on which the server socket is bound
     */
    function start(Reactor $reactor, $uri) {
        $this->reactor = $reactor;
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        if (!$socket = stream_socket_server($uri, $errno, $errstr, $flags)) {
            throw new \RuntimeException(
                sprintf("Failed binding server socket to %s: [%d] %s", $uri, $errno, $errstr)
            );
        }

        stream_set_blocking($socket, FALSE);

        $this->uri = $uri;
        $this->socket = $socket;
        $this->acceptWatcher = $this->reactor->onReadable($socket, function() {
            $this->accept();
        });

        return $uri;
    }

    private function accept() {
        while ($client = @stream_socket_accept($this->socket, $timeout = 0)) {
            $this->acceptClient($client);
        }
    }

    private function acceptClient($client) {
        stream_set_blocking($client, FALSE);

        $clientId = (int) $client;
        $watcherId = $this->reactor->onReadable($client, function($watcherId, $client) {
            $this->readFromClient($client);
        });

        $this->clients[$clientId] = [$watcherId, $client];

        if ($callback = $this->onClient) {
            $callback($clientId);
        }
    }

    private function readFromClient($client) {
        $clientId = (int) $client;
        if ($signal = trim(fgets($client))) {
            printf("readable in pid %s", getmypid());
            $this->receiveSignal($clientId, $signal);
        } elseif (!is_resource($client) || @feof($client)) {
            $this->doClose($clientId);
        }
    }

    private function receiveSignal($clientId, $signal) {
        if ($callback = $this->onSignal) {
            $callback($clientId, $signal);
        }
    }

    /**
     * Send data to the specified client
     *
     * Note that sends are blocking because it just doesn't matter and who really cares.
     *
     * @param int $clientId
     * @param string $data
     * @throws \DomainException On unknown client ID
     * @return bool Returns TRUE on send success or FALSE on failure
     */
    function send($clientId, $data) {
        if (isset($this->clients[$clientId])) {
            return $this->doSend($clientId, $data);
        } else {
            throw new \DomainException(
                sprintf('Unknown client ID: %s', $clientId)
            );
        }
    }

    private function doSend($clientId, $data) {
        $data = rtrim($data) . "\r\n";
        $client = $this->clients[$clientId][1];
        stream_set_blocking($client, TRUE);
        $bytesWritten = @fwrite($client, $data);
        stream_set_blocking($client, FALSE);

        return (bool) $bytesWritten;
    }

    /**
     * Broadcast a message to all connected clients
     *
     * @param string $data
     * @return \Aerys\Framework\IpcServer Returns the current object instance
     */
    function broadcast($data) {
        foreach (array_keys($this->clients) as $clientId) {
            $this->doSend($clientId, $data);
        }

        return $this;
    }

    /**
     * Close the specified client connection
     *
     * @param int $clientId
     * @throws \DomainException On unknown client ID
     * @return \Aerys\Framework\IpcServer Returns the current object instance
     */
    function close($clientId) {
        if (isset($this->clients[$clientId])) {
            $this->doClose($clientId);
        } else {
            throw new \DomainException(
                sprintf('Unknown client ID: %s', $clientId)
            );
        }

        return $this;
    }

    private function doClose($clientId) {
        list($watcherId, $client) = $this->clients[$clientId];
        unset($this->clients[$clientId]);

        $this->reactor->cancel($watcherId);

        stream_socket_shutdown($client, STREAM_SHUT_RDWR);

        if ($callback = $this->onClose) {
            $callback($clientId);
        }

        if ($this->isShuttingDown && !$this->count() && $onShutdown = $this->onShutdown) {
            $onShutdown();
        }
    }

    /**
     * Close all connected clients
     *
     * @return \Aerys\Framework\IpcServer Returns the current object instance
     */
    function closeAll() {
        foreach (array_keys($this->clients) as $clientId) {
            $this->doClose($clientId);
        }

        return $this;
    }

    /**
     * Temporarily pause client acceptance and reading
     *
     * @return \Aerys\Framework\IpcServer Returns the current object instance
     */
    function pause() {
        if ($this->acceptWatcher) {
            $this->reactor->disable($this->acceptWatcher);
        }

        foreach ($this->clients as $clientArr) {
            $watcherId = $clientArr[0];
            $this->reactor->disable($watcherId);
        }

        return $this;
    }

    /**
     * Resume client acceptance and reading
     *
     * @return \Aerys\Framework\IpcServer Returns the current object instance
     */
    function resume() {
        if ($this->acceptWatcher) {
            $this->reactor->enable($this->acceptWatcher);
        }

        foreach ($this->clients as $clientArr) {
            $watcherId = $clientArr[0];
            $this->reactor->enable($watcherId);
        }

        return $this;
    }

    /**
     * Shutdown the server with an optional callback on completion
     *
     * @param callable $onShutdown An option callback to be invoked once all clients are closed
     * @return void
     */
    function shutdown(callable $onShutdown = NULL) {
        $this->reactor->cancel($this->acceptWatcher);
        $this->acceptWatcher = NULL;
        $this->isShuttingDown = TRUE;
        $this->onShutdown = $onShutdown;

        if ($this->count()) {
            $this->closeAll();
        } else {
            $onShutdown();
        }

        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }

    /**
     * Counts the number of connected clients
     *
     * @return int Returns the number of connected clients
     */
    function count() {
        return count($this->clients);
    }

}

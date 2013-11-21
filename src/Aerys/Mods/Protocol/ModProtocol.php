<?php

namespace Aerys\Mods\Protocol;

use Alert\Reactor,
    Aerys\Server,
    Aerys\Mods\BeforeResponseMod;

class ModProtocol implements BeforeResponseMod {

    const CLOSE_USER_ERROR = 1;
    const CLOSE_SOCKET_GONE = 2;
    const CLOSE_UNSPECIFIED = 3;

    private $reactor;
    private $server;
    private $handlers;
    private $clients = [];

    private $canUseExtSockets;
    private $socketReadGranularity = 65535;

    function __construct(Reactor $reactor, Server $server) {
        $this->reactor = $reactor;
        $this->server = $server;
        $this->handlers = new \SplObjectStorage;
        $this->canUseExtSockets = extension_loaded('sockets');
    }

    /**
     * Register a protocol handler implementation
     *
     * @param ProtocolHander $handler
     * @return void
     */
    function registerProtocolHandler(ProtocolHandler $handler) {
        $this->handlers->attach($handler);
    }

    /**
     * Invoked before HTTP responses are returned from the server
     *
     * ModProtocol works by intercepting 400 Bad Request HTTP responses and determining if what the
     * HTTP server interpreted as a bad request (because it wasn't an HTTP message) actually matches
     * one a custom protocol. This method is invoked just before Aerys sends a response and if it
     * meets our custom protocol criteria the relevant socket client is exported to one of the
     * handler classes registered with ModProtocol.
     *
     * @param int $requestId
     * @return void
     */
    function beforeResponse($requestId) {
        $asgiResponse = $this->server->getResponse($requestId);

        if ($asgiResponse[0] == 400) {
            $rejectedHttpTrace = $this->server->getTrace($requestId);
            $this->performHandlerNegotiation($requestId, $rejectedHttpTrace);
        }
    }

    private function performHandlerNegotiation($requestId, $rejectedHttpTrace) {
        $socketInfo = $this->server->querySocket($requestId);

        foreach ($this->handlers as $handler) {
            if ($handler->negotiate($rejectedHttpTrace, $socketInfo)) {
                $socket = $this->server->exportSocket($requestId);
                $this->importSocket($handler, $socket, $rejectedHttpTrace, $socketInfo);
                break;
            }
        }
    }

    private function importSocket(ProtocolHandler $handler, $socket, $openingMsg, array $socketInfo) {
        $client = new Client;

        $socketId = (int) $socket;

        $client->id = $socketId;
        $client->socket = $socket;
        $client->handler = $handler;
        $client->clientAddress = $socketInfo['clientAddress'];
        $client->clientPort = $socketInfo['clientPort'];
        $client->serverAddress = $socketInfo['serverAddress'];
        $client->serverPort = $socketInfo['serverPort'];
        $client->isEncrypted = $socketInfo['isEncrypted'];
        $client->importedAt = $socketInfo['importedAt'] = microtime(TRUE);

        $onReadable = function() use ($client) { $this->onReadableSocket($client); };
        $onWritable = function() use ($client) { $this->writeClientBuffer($client); };

        $client->readWatcher = $this->reactor->onReadable($socket, $onReadable);
        $client->writeWatcher = $this->reactor->onWritable($socket, $onWritable, $enableNow = FALSE);
        $this->clients[$socketId] = $client;
        $this->doHandlerOnOpen($client, $openingMsg, $socketInfo);
    }

    private function doHandlerOnOpen(Client $client, $openingMsg, array $socketInfo) {
        try {
            $client->handler->onOpen($client->id, $openingMsg, $socketInfo);
        } catch (\Exception $e) {
            $this->logError($e);
            $this->doSocketClose($client, self::CLOSE_USER_ERROR);
        }
    }

    private function logError($e) {
        $this->server->logError($e);
    }

    private function doSocketClose(Client $client, $closeReason) {
        unset($this->clients[$client->id]);
        $this->reactor->cancel($client->readWatcher);
        $this->reactor->cancel($client->writeWatcher);
        $this->server->closeExportedSocket($client->socket);
        $client->handler->onClose($client->id, $closeReason);
    }

    private function onReadableSocket(Client $client) {
        $data = @fread($client->socket, $this->socketReadGranularity);

        if ($data || $data === '0') {
            $client->bytesRead += strlen($data);
            $this->doHandlerOnData($client, $data);
        } elseif (!is_resource($client->socket) || @feof($client->socket)) {
            $this->doSocketClose($client, self::CLOSE_SOCKET_GONE);
        }
    }

    private function doHandlerOnData(Client $client, $data) {
        try {
            $client->handler->onData($client->id, $data);
        } catch (\Exception $e) {
            $this->logError($e);
            $this->doSocketClose($client, self::CLOSE_USER_ERROR);
        }
    }

    /**
     * Write data to a specific socket
     *
     * Because all socket IO is non-blocking this function will return immediately and you won't
     * actually know when all the data has been written to the remote client. Instead, the write
     * takes place in parallel and finishes whenever it finishes. If you need to know when the write
     * actually completes you can specify an $onCompletion callback for notification.
     *
     * @param int $socketId The ID of the socket data recipient
     * @param string $data The raw data to send the client
     * @param callable $onCompletion An optional callback to notify when the write completes
     * @return void
     */
    function write($socketId, $data, callable $onCompletion = NULL) {
        if (!isset($this->clients[$socketId])) {
            throw new \DomainException(
                "Unknown socket ID {$socketId}"
            );
        }

        $client = $this->clients[$socketId];

        if (!$client->isShutdownForWriting) {
            $writeBuffer = [$data, $bytesRemaining = strlen($data), $onCompletion];
            $client->writeBuffer[] = $writeBuffer;
            $this->writeClientBuffer($client, $data, $onCompletion);
        }
    }

    private function writeClientBuffer(Client $client) {
        list($data, $bytesRemaining, $onCompletion) = $client->writeBuffer[0];

        $bytesWritten = @fwrite($client->socket, $data);

        $client->bytesSent += $bytesWritten;

        if ($bytesWritten === $bytesRemaining) {
            $this->finalizeBufferWrite($client, $onCompletion);
        } elseif (is_resource($client->socket)) {
            $data = substr($data, $bytesWritten);
            $bytesRemaining -= $bytesWritten;
            $client->writeBuffer[0] = [$data, $bytesRemaining, $onCompletion];
            $this->reactor->enable($client->writeWatcher);
        } else {
            $this->doSocketClose($client, self::CLOSE_SOCKET_GONE);
        }
    }

    private function finalizeBufferWrite(Client $client, $onCompletion) {
        array_shift($client->writeBuffer);

        if ($client->writeBuffer) {
            $this->reactor->enable($client->writeWatcher);
        } else {
            $this->reactor->disable($client->writeWatcher);
        }

        if ($onCompletion) {
            // @TODO Maybe protect against uncaught exceptions with try/catch?
            $onCompletion($client->id);
        }
    }

    private function doHandlerOnWriteCompletion(Client $client, callable $onCompletion) {
        try {
            $onCompletion();
        } catch (\Exception $e) {
            $this->logError($e);
            $this->doSocketClose($client, self::CLOSE_USER_ERROR);
        }
    }

    /**
     * Disconnect a socket client with an optional reason code
     *
     * @param int $socketId The ID of the socket data recipient
     * @param int $closeReason An optional close reason
     * @return void
     */
    function close($socketId, $closeReason = self::CLOSE_UNSPECIFIED) {
        if (isset($this->clients[$socketId])) {
            $client = $this->clients[$socketId];
            $this->doSocketClose($client, $closeReason);
        } else {
            throw new \DomainException(
                "Unknown socket ID {$socketId}"
            );
        }
    }

    /**
     * Ask ModProxy for information about a given socket
     *
     * @param int $socketId A socket identifier
     * @return array Returns an array of data about the socket in question
     */
    function query($socketId) {
        if (isset($this->clients[$socketId])) {
            $client = $this->clients[$socketId];
            return [
                'importedAt' => $client->importedAt,
                'clientAddress' => $client->clientAddress,
                'clientPort' => $client->clientPort,
                'serverAddress' => $client->serverAddress,
                'serverPort' => $client->serverPort,
                'bytesRead' => $client->bytesRead,
                'bytesSent' => $client->bytesSent
            ];
        } else {
            throw new \DomainException(
                "Unknown socket ID {$socketId}"
            );
        }
    }

    /**
     * Shutdown the socket for all reading going forward (cannot be reenabled)
     *
     * @param int $socketId
     * @throws \DomainException on Unknown socket ID
     * @return void
     */
    function stopReading($socketId) {
        if (isset($this->clients[$socketId])) {
            $client = $this->clients[$socketId];
            $this->reactor->disable($client->readWatcher);
            stream_socket_shutdown($client->socket, STREAM_SHUT_RD);
        } else {
            throw new \DomainException(
                "Unknown socket ID {$socketId}"
            );
        }
    }

    /**
     * Shutdown the socket for all writing going forward (cannot be reenabled)
     *
     * @param int $socketId
     * @throws \DomainException on Unknown socket ID
     * @return void
     */
    function stopWriting($socketId) {
        if (!isset($this->clients[$socketId])) {
            throw new \DomainException(
                "Unknown socket ID {$socketId}"
            );
        }

        $client = $this->clients[$socketId];
        $client->isShutdownForWriting = TRUE;
        $this->reactor->disable($client->writeWatcher);

        stream_socket_shutdown($client->socket, STREAM_SHUT_WR);
    }

    /**
     * Set multiple option values at once
     *
     * @param array $options A key value array mapping option keys to their values
     * @throws \DomainException On unknown option key
     * @return void
     */
    function setAllOptions(array $options) {
        foreach ($options as $key => $value) {
            $this->setOption($key, $value);
        }
    }

    /**
     * Set an option value
     *
     * @param string $option
     * @param mixed $value
     * @throws \DomainException On unknown option key
     * @return void
     */
    function setOption($option, $value) {
        switch (strtolower($option)) {
            case 'socketreadgranularity':
                $this->setSocketReadGranularity($value);
                break;
            default:
                throw new \DomainException(
                    "Unkown option: {$option}"
                );
        }
    }

    private function setSocketReadGranularity($bytes) {
        $this->socketReadGranularity = filter_var($bytes, FILTER_VALIDATE_INT, ['options' => [
            'default' => 65536,
            'min_range' => 1
        ]]);
    }

}

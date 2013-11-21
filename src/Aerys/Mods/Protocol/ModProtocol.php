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
    private $connections = [];

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

    /**
     * Invoked before HTTP responses are returned from the server
     *
     * ModProtocol works by intercepting 400 Bad Request HTTP responses and determining if what the
     * HTTP server interpreted as a bad request (because it wasn't an HTTP message) actually matches
     * one a custom protocol. This method is invoked just before Aerys sends a response and if it
     * meets our custom protocol criteria the relevant socket connection is exported to one of the
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
        $conn = new Connection;

        $socketId = (int) $socket;

        $conn->id = $socketId;
        $conn->socket = $socket;
        $conn->handler = $handler;
        $conn->clientAddress = $socketInfo['clientAddress'];
        $conn->clientPort = $socketInfo['clientPort'];
        $conn->serverAddress = $socketInfo['serverAddress'];
        $conn->serverPort = $socketInfo['serverPort'];
        $conn->isEncrypted = $socketInfo['isEncrypted'];
        $conn->importedAt = $socketInfo['importedAt'] = microtime(TRUE);

        $onReadable = function() use ($conn) { $this->onReadableSocket($conn); };
        $onWritable = function() use ($conn) { $this->writeConnectionBuffer($conn); };

        $conn->readWatcher = $this->reactor->onReadable($socket, $onReadable);
        $conn->writeWatcher = $this->reactor->onWritable($socket, $onWritable, $enableNow = FALSE);
        $this->connections[$socketId] = $conn;
        $this->doHandlerOnOpen($conn, $openingMsg, $socketInfo);
    }

    private function doHandlerOnOpen(Connection $conn, $openingMsg, array $socketInfo) {
        try {
            $conn->handler->onOpen($conn->id, $openingMsg, $socketInfo);
        } catch (\Exception $e) {
            $this->logError($e);
            $this->doSocketClose($conn, self::CLOSE_USER_ERROR);
        }
    }

    private function logError($e) {
        $this->server->logError($e);
    }

    private function doSocketClose(Connection $conn, $closeReason) {
        unset($this->connections[$conn->id]);

        $this->reactor->cancel($conn->readWatcher);
        $this->reactor->cancel($conn->writeWatcher);

        $this->server->closeExportedSocket($conn->socket);
        $conn->handler->onClose($conn->id, $closeReason);
    }

    private function onReadableSocket(Connection $conn) {
        $data = @fread($conn->socket, $this->socketReadGranularity);

        if ($data || $data === '0') {
            $conn->bytesRead += strlen($data);
            $this->doHandlerOnData($conn, $data);
        } elseif (!is_resource($conn->socket) || @feof($conn->socket)) {
            $this->doSocketClose($conn, self::CLOSE_SOCKET_GONE);
        }
    }

    private function doHandlerOnData(Connection $conn, $data) {
        try {
            $conn->handler->onData($conn->id, $data);
        } catch (\Exception $e) {
            $this->logError($e);
            $this->doSocketClose($conn, self::CLOSE_USER_ERROR);
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
        if (!isset($this->connections[$socketId])) {
            throw new \DomainException(
                "Unknown socket ID {$socketId}"
            );
        }

        $conn = $this->connections[$socketId];

        if (!$conn->isShutdownForWriting) {
            $writeBuffer = [$data, $bytesRemaining = strlen($data), $onCompletion];
            $conn->writeBuffer[] = $writeBuffer;
            $this->writeConnectionBuffer($conn, $data, $onCompletion);
        }
    }

    private function writeConnectionBuffer(Connection $conn) {
        list($data, $bytesRemaining, $onCompletion) = $conn->writeBuffer[0];

        $bytesWritten = @fwrite($conn->socket, $data);

        $conn->bytesSent += $bytesWritten;

        if ($bytesWritten === $bytesRemaining) {
            $this->finalizeBufferWrite($conn, $onCompletion);
        } elseif (is_resource($client->socket)) {
            $data = substr($data, $bytesWritten);
            $bytesRemaining -= $bytesWritten;
            $conn->writeBuffer[0] = [$data, $bytesRemaining, $onCompletion];
            $this->reactor->enable($conn->writeWatcher);
        } else {
            $this->doSocketClose($conn, self::CLOSE_SOCKET_GONE);
        }
    }

    private function finalizeBufferWrite(Connection $conn, $onCompletion) {
        array_shift($conn->writeBuffer);

        if ($conn->writeBuffer) {
            $this->reactor->enable($conn->writeWatcher);
        } else {
            $this->reactor->disable($conn->writeWatcher);
        }

        if ($onCompletion) {
            // @TODO Maybe protect against uncaught exceptions with try/catch?
            $onCompletion($conn->id);
        }
    }

    private function doHandlerOnWriteCompletion(Connection $conn, callable $onCompletion) {
        try {
            $onCompletion();
        } catch (\Exception $e) {
            $this->logError($e);
            $this->doSocketClose($conn, self::CLOSE_USER_ERROR);
        }
    }

    /**
     * Close a socket connection with an optional reason code
     *
     * @param int $socketId The ID of the socket data recipient
     * @param int $closeReason An optional close reason
     * @return void
     */
    function close($socketId, $closeReason = self::CLOSE_UNSPECIFIED) {
        if (isset($this->connections[$socketId])) {
            $conn = $this->connections[$socketId];
            $this->doSocketClose($conn, $closeReason);
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
        if (isset($this->connections[$socketId])) {
            $conn = $this->connections[$socketId];
            return [
                'importedAt' => $conn->importedAt,
                'clientAddress' => $conn->clientAddress,
                'clientPort' => $conn->clientPort,
                'serverAddress' => $conn->serverAddress,
                'serverPort' => $conn->serverPort,
                'bytesRead' => $conn->bytesRead,
                'bytesSent' => $conn->bytesSent
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
        if (isset($this->connections[$socketId])) {
            $conn = $this->connections[$socketId];
            $this->reactor->disable($conn->readWatcher);
            stream_socket_shutdown($conn->socket, STREAM_SHUT_RD);
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
        if (!isset($this->connections[$socketId])) {
            throw new \DomainException(
                "Unknown socket ID {$socketId}"
            );
        }

        $conn = $this->connections[$socketId];
        $conn->isShutdownForWriting = TRUE;
        $this->reactor->disable($conn->writeWatcher);

        stream_socket_shutdown($conn->socket, STREAM_SHUT_WR);
    }

}

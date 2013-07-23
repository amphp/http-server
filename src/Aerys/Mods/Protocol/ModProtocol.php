<?php

/**
 * $config = [
 *     'my-app' => [
 *         'listenOn'      => '*:80',
 *         'name'          => 'mysite.com',
 *         'application'   => new DocRootLauncher([
 *             'docRoot'   => '/path/to/doc/root'
 *         ]),
 *         'mods' => [
 *             'protocol'   => [
 *                 'options' => [
 *                     'socketReadTimeout' => -1,
 *                     'socketReadGranularity' => 65535
 *                 ],
 *                 'handlers' => [ // Negotiations attempted in the order in which they're specified
 *                     'Handler1',
 *                     'Handler2',
 *                     'Handler3'
 *                 ]
 *             ]
 *         ]
 *     ]
 * ];
 */

namespace Aerys\Mods\Protocol;

use Amp\Reactor,
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
    private $socketReadTimeout = -1;
    private $socketReadGranularity = 65535;
    
    function __construct(Reactor $reactor, Server $server) {
        $this->reactor = $reactor;
        $this->server = $server;
        $this->handlers = new \SplObjectStorage;
        $this->canUseExtSockets = extension_loaded('sockets');
    }
    
    function registerProtocolHandler(ProtocolHandler $handler) {
        $this->handlers->attach($handler);
    }
    
    function setAllOptions(array $options) {
        foreach ($options as $key => $value) {
            $this->setOption($key, $value);
        }
    }
    
    function setOption($option, $value) {
        switch (strtolower($option)) {
            case 'socketreadtimeout':
                $this->setSocketReadTimeout($value);
                break;
            case 'socketreadgranularity':
                $this->setSocketReadGranularity($value);
                break;
            default:
                throw new \DomainException(
                    "Unkown option: {$option}"
                );
        }
    }
    
    private function setSocketReadTimeout($seconds) {
        $this->socketReadTimeout = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
            'default' => -1,
            'min_range' => -1
        ]]);
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
        foreach ($this->handlers as $handler) {
            if ($handler->negotiate($rejectedHttpTrace)) {
                $socket = $this->server->exportSocket($requestId);
                $this->importSocket($handler, $socket);
                break;
            }
        }
    }
    
    private function importSocket(ProtocolHandler $handler, $socket) {
        $conn = new Connection;
        
        $socketId = (int) $socket;
        
        $conn->id = $socketId;
        $conn->socket = $socket;
        $conn->handler = $handler;
        $conn->clientName = stream_socket_get_name($socket, TRUE);
        $conn->serverName = stream_socket_get_name($socket, FALSE);
        $conn->isEncrypted = isset(stream_context_get_options($socket)['ssl']);
        $conn->importedAt = microtime(TRUE);
        
        $timeout = $this->socketReadTimeout;
        $onReadable = function($socket, $trigger) use ($conn) {
            $this->onReadableSocket($conn, $trigger);
        };
        
        $conn->readSubscription = $this->reactor->onReadable($socket, $onReadable, $timeout);
        $this->connections[$socketId] = $conn;
        $this->doHandlerOnOpen($conn);
    }
    
    private function doHandlerOnOpen(Connection $conn) {
        try {
            $conn->handler->onOpen($conn->id);
        } catch (\Exception $e) {
            $this->logError($e);
            $this->doSocketClose($conn, self::CLOSE_USER_ERROR);
        }
    }
    
    private function logError($e) {
        $errorStream = $this->server->getErrorStream();
        @fwrite($errorStream, $e);
    }
    
    private function doSocketClose(Connection $conn, $closeReason) {
        unset($this->connections[$conn->id]);
        
        $conn->readSubscription->cancel();
        
        if ($conn->writeSubscription) {
            $conn->writeSubscription->cancel();
        }
        
        $this->server->closeExportedSocket($conn->socket);
        $conn->handler->onClose($conn->id, $closeReason);
    }
    
    private function onReadableSocket(Connection $conn, $trigger) {
        if ($trigger === Reactor::READ) {
            $this->doSocketRead($conn);
        } else {
            $this->doHandlerOnTimeout($conn);
        }
    }
    
    private function doSocketRead(Connection $conn) {
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
    
    private function doHandlerOnTimeout(Connection $conn) {
        try {
            $conn->handler->onTimeout($conn->id);
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
        if (isset($this->connections[$socketId])) {
            $writeBuffer = [$data, $bytesRemaining = strlen($data), $onCompletion];
            $conn = $this->connections[$socketId];
            $conn->writeBuffer[] = $writeBuffer;
            $this->writeConnectionBuffer($conn, $data, $onCompletion);
        } else {
            throw new \DomainException(
                "Unknown socket ID {$socketId}"
            );
        }
    }
    
    private function writeConnectionBuffer(Connection $conn) {
        list($data, $bytesRemaining, $onCompletion) = $conn->writeBuffer[0];
        
        $bytesWritten = @fwrite($conn->socket, $data);
        
        $conn->bytesSent += $bytesWritten;
        
        if ($bytesWritten === $bytesRemaining) {
            $this->finalizeBufferWrite($conn, $onCompletion);
        } elseif ($bytesWritten) {
            $data = substr($data, $bytesWritten);
            $bytesRemaining -= $bytesWritten;
            $conn->writeBuffer[0] = [$data, $bytesRemaining, $onCompletion];
            $this->enableWriteSubscription($client);
        } elseif (is_resource($client->socket)) {
            $this->enableWriteSubscription($client);
        } else {
            $this->doSocketClose($conn, self::CLOSE_SOCKET_GONE);
        }
    }
    
    private function finalizeBufferWrite(Connection $conn, $onCompletion) {
        array_shift($conn->writeBuffer);
        
        if ($conn->writeBuffer) {
            $this->enableWriteSubscription($client);
        } elseif ($conn->writeSubscription) {
            $conn->writeSubscription->disable();
        }
        
        if ($onCompletion) {
            // @TODO Maybe protect against uncaught exceptions with try/catch?
            $onCompletion();
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
    
    private function enableWriteSubscription(Connection $conn) {
        if ($conn->writeSubscription) {
            $conn->writeSubscription->enable();
        } else {
            $subscription = $this->reactor->onWritable($conn->socket, function() use ($conn) {
                $this->writeConnectionBuffer($conn);
            });
            $conn->writeSubscription = $subscription;
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
            return $this->generateSocketInfoArray($conn);
        } else {
            throw new \DomainException(
                "Unknown socket ID {$socketId}"
            );
        }
    }
    
    private function generateSocketInfoArray(Connection $conn) {
        return [
            'importedAt' => $conn->importedAt,
            'clientName' => $conn->clientName,
            'serverName' => $conn->serverName,
            'bytesRead' => $conn->bytesRead,
            'bytesSent' => $conn->bytesSent
        ];
    }
    
}

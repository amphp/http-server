<?php

namespace Aerys\Websocket;

use Amp\Success;
use Amp\Failure;
use Amp\Future;
use Aerys\Server;
use Aerys\Responder;
use Aerys\ResponderStruct;
use Aerys\ClientGoneException;

class HandshakeResponder implements Responder {
    private $responderStruct;
    private $endpoint;
    private $buffer;
    private $bufferSize;
    private $isWatcherEnabled;
    private $promisor;

    /**
     * @param \Aerys\Websocket\Endpoint $endpoint
     * @param string $handshakeResponse
     */
    public function __construct(Endpoint $endpoint, $handshakeResponse) {
        $this->endpoint = $endpoint;
        $this->buffer = $handshakeResponse;
        $this->bufferSize = strlen($handshakeResponse);
    }

    /**
     * Prepare the Responder
     *
     * @param Aerys\ResponderStruct $responderStruct
     */
    public function prepare(ResponderStruct $responderStruct) {
        $this->responderStruct = $responderStruct;
    }

    /**
     * Write the prepared response
     *
     * @return \Amp\Promise
     */
    public function write() {
        $responderStruct = $this->responderStruct;
        $bytesWritten = @fwrite($responderStruct->socket, $this->buffer);

        if ($bytesWritten === $this->bufferSize) {
            goto write_complete;
        } elseif ($bytesWritten !== false) {
            goto write_incomplete;
        } else {
            goto write_error;
        }

        write_complete: {
            if ($this->isWatcherEnabled) {
                $responderStruct->reactor->disable($responderStruct->writeWatcher);
                $this->isWatcherEnabled = false;
            }

            $mustClose = false;
            $request = $responderStruct->request;
            $server = $responderStruct->server;
            $socketId = $request['AERYS_SOCKET_ID'];
            list($socket, $onClose) = $server->exportSocket($socketId);
            $this->endpoint->import($socket, $onClose, $request);

            return $this->promisor ? $this->promisor->succeed($mustClose) : new Success($mustClose);
        }

        write_incomplete: {
            $this->bufferSize -= $bytesWritten;
            $this->buffer = substr($this->buffer, $bytesWritten);

            if (!$this->isWatcherEnabled) {
                $this->isWatcherEnabled = true;
                $responderStruct->reactor->enable($responderStruct->writeWatcher);
            }

            return $this->promisor ?: ($this->promisor = new Future($responderStruct->reactor));
        }

        write_error: {
            if ($this->isWatcherEnabled) {
                $this->isWatcherEnabled = false;
                $responderStruct->reactor->disable($responderStruct->writeWatcher);
            }

            $error = new ClientGoneException(
                'Write failed: destination stream went away'
            );

            return $this->promisor ? $this->promisor->fail($error) : new Failure($error);
        }
    }
}

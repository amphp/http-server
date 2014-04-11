<?php

namespace Aerys\Documents;

use Amp\Thread;

class ThreadSendTask {
    private $headers;
    private $path;
    private $socket;
    private $socketId;

    public function __construct($headers, $path, $socket) {
        $this->headers = $headers;
        $this->path = $path;
        $this->socket = $socket;

        /**
         * It's important to store the socket ID here and not inside the run()
         * method as the ID number will change once the socket is imported into
         * the worker thread's context.
         */
        $this->socketId = (int) $socket;
    }

    public function run() {
        if (!isset($this->worker->shared[$this->socketId])) {
            $this->worker->shared[$this->socketId] = $this->socket;
        }

        $handle = @fopen($this->path, 'r');

        if ($handle === FALSE) {
            throw new \RuntimeException(
                sprintf('Failed opening file handle: %s', $this->path)
            );
        }

        if (!@fwrite($this->socket, $this->headers)) {
            throw new \RuntimeException(
                'Failed writing response headers to socket'
            );
        }

        $result = @stream_copy_to_stream($handle, $this->socket);

        if ($result === FALSE) {
            throw new \RuntimeException(
                sprintf('Failed writing file handle to socket: %s', $path)
            );
        }

        $this->worker->registerResult(Thread::SUCCESS, $result);
    }
}

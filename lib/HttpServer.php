<?php

namespace Aerys;

use Amp\Reactor;

interface HttpServer extends ServerObserver {
    /**
     * Import a client socket stream for HTTP protocol manipulation
     *
     * @param resource $socket
     * @return void
     */
    public function import($socket);

    /**
     * React to socket readability
     *
     * @param \Amp\Reactor $reactor
     * @param string $watcherId
     * @param resource $socket
     * @param mixed $callbackData
     * @return void
     */
    public function onReadable(Reactor $reactor, string $watcherId, $socket, $callbackData);

    /**
     * React to socket writability
     *
     * @param \Amp\Reactor $reactor
     * @param string $watcherId
     * @param resource $socket
     * @param mixed $callbackData
     * @return void
     */
    public function onWritable(Reactor $reactor, string $watcherId, $socket, $callbackData);

    /**
     * React to parse events
     *
     * @param array $parseStruct
     * @param mixed $callbackData
     * @return void
     */
    public function onParse(array $parseStruct, $callbackData);
}
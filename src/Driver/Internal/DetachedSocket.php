<?php

namespace Amp\Http\Server\Driver\Internal;

use Amp\Http\Server\Driver\Client;
use Amp\Socket\ServerSocket;

/** @internal */
final class DetachedSocket extends ServerSocket {
    /** @var callable */
    private $client;

    /**
     * @param Client $client
     * @param resource $resource
     * @param int $chunkSize
     */
    public function __construct(Client $client, $resource, int $chunkSize = ServerSocket::DEFAULT_CHUNK_SIZE) {
        parent::__construct($resource, $chunkSize);
        $this->client = $client;
    }

    public function close() {
        parent::close();
        $this->client->close();
        $this->client = null;
    }

    public function __destruct() {
        if ($this->client) {
            $this->client->close();
        }
    }
}

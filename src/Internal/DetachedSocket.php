<?php

namespace Amp\Http\Server\Internal;

use Amp\Http\Server\Client;
use Amp\Socket\ServerSocket;

class DetachedSocket extends ServerSocket {
    /** @var callable */
    private $client;

    /**
     * @param Client $client
     * @param resource $resource
     * @param int $chunkSize
     */
    public function __construct(Client $client, $resource, int $chunkSize = 65536) {
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

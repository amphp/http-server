<?php

namespace Amp\Http\Server\Driver\Internal;

use Amp\Http\Server\Driver\Client;
use Amp\Promise;
use Amp\Socket\ServerSocket;
use Amp\Success;

/** @internal */
final class DetachedSocket extends ServerSocket
{
    /** @var callable */
    private $client;

    /** @var string|null */
    private $buffer;

    /**
     * @param Client $client
     * @param resource $resource
     * @param string Remaining buffer previously read from the socket.
     * @param int $chunkSize
     */
    public function __construct(Client $client, $resource, string $buffer, int $chunkSize = ServerSocket::DEFAULT_CHUNK_SIZE)
    {
        parent::__construct($resource, $chunkSize);
        $this->client = $client;
        $this->buffer = $buffer !== '' ? $buffer : null;
    }

    public function read(): Promise
    {
        if ($this->buffer !== null) {
            $buffer = $this->buffer;
            $this->buffer = null;
            return new Success($buffer);
        }

        return parent::read();
    }

    public function close()
    {
        parent::close();
        $this->client->close();
        $this->client = null;
    }

    public function __destruct()
    {
        if ($this->client) {
            $this->client->close();
        }
    }
}

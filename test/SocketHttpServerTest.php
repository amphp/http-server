<?php declare(strict_types=1);

namespace Amp\Http\Server\Test;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Driver\HttpDriver;
use Amp\Http\Server\Driver\HttpDriverFactory;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket;
use Psr\Log\NullLogger;
use function Amp\delay;

class SocketHttpServerTest extends AsyncTestCase
{
    public function testClientIsClosedWhenDriverReturns(): void
    {
        $driver = new class implements HttpDriver {
            public ?Client $client = null;

            public function handleClient(
                Client $client,
                ReadableStream $readableStream,
                WritableStream $writableStream,
            ): void {
                $this->client = $client;
                // Return normally, as a driver does when the connection ends
                // without throwing.
            }

            public function getPendingRequestCount(): int
            {
                return 0;
            }

            public function getApplicationLayerProtocols(): array
            {
                return [];
            }

            public function stop(): void
            {
            }
        };

        $driverFactory = new class($driver) implements HttpDriverFactory {
            public function __construct(private readonly HttpDriver $driver)
            {
            }

            public function createHttpDriver(
                RequestHandler $requestHandler,
                ErrorHandler $errorHandler,
                Client $client,
            ): HttpDriver {
                return $this->driver;
            }

            public function getApplicationLayerProtocols(): array
            {
                return [];
            }
        };

        $server = new SocketHttpServer(
            new NullLogger(),
            new Socket\ResourceServerSocketFactory(),
            new SocketClientFactory(new NullLogger()),
            httpDriverFactory: $driverFactory,
        );

        $server->expose(Socket\SocketAddress\fromString('127.0.0.1:0'));
        $server->start(
            new ClosureRequestHandler(fn () => new Response(HttpStatus::OK)),
            new DefaultErrorHandler(),
        );

        try {
            $client = Socket\connect($server->getServers()[0]->getAddress()->toString());
            $client->write("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
            delay(0.1);
            $client->close();
            delay(0.1);

            self::assertNotNull($driver->client, 'Driver did not receive a client');
            self::assertTrue(
                $driver->client->isClosed(),
                'Client was not closed after the driver returned; its socket and '
                    . 'event-loop watcher leak for the lifetime of the process',
            );
        } finally {
            $server->stop();
        }
    }
}

<?php

namespace Amp\Http\Server\Test;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as ClientRequest;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket;
use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\delay;

class ServerTest extends AsyncTestCase
{
    public function testEmptySocketArray(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Argument #1 ($sockets) can\'t be an empty array');
        new HttpServer([], new ClosureRequestHandler(function () {
            return new Response;
        }), $this->createMock(PsrLogger::class));
    }

    public function testShutdownWaitsOnUnfinishedResponses(): void
    {
        $socket = Socket\listen("tcp://127.0.0.1:0");
        $server = new HttpServer([$socket], new ClosureRequestHandler(function () {
            delay(0.2);
            return new Response(Status::NO_CONTENT);
        }), $this->createMock(PsrLogger::class));

        $server->start();

        $request = new ClientRequest("http://" . $socket->getAddress() . "/");

        $response = HttpClientBuilder::buildDefault()->request($request);

        // Ensure client already connected and sent request
        delay(0.1);

        $server->stop();

        self::assertSame(Status::NO_CONTENT, $response->getStatus());
    }
}

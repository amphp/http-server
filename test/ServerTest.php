<?php

namespace Amp\Http\Server\Test;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as ClientRequest;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
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
        $this->expectExceptionMessage('Argument 1 can\'t be an empty array');
        new HttpServer([], new CallableRequestHandler(function () {
            return new Response;
        }), $this->createMock(PsrLogger::class));
    }

    public function testShutdownWaitsOnUnfinishedResponses(): void
    {
        $socket = Socket\Server::listen("tcp://127.0.0.1:0");
        $server = new HttpServer([$socket], new CallableRequestHandler(function () {
            delay(200);
            return new Response(Status::NO_CONTENT);
        }), $this->createMock(PsrLogger::class));

        $server->start();

        $request = new ClientRequest("http://" . $socket->getAddress() . "/");

        $response = HttpClientBuilder::buildDefault()->request($request);

        // Ensure client already connected and sent request
        delay(100);

        $server->stop();

        $this->assertSame(Status::NO_CONTENT, $response->getStatus());
    }
}

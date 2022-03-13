<?php

namespace Amp\Http\Server\Test\Driver;

use Amp\ByteStream\ReadableBuffer;
use Amp\Cancellation;
use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as ClientRequest;
use Amp\Http\Cookie\ResponseCookie;
use Amp\Http\Server\HttpSocketServer;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket;
use Amp\Socket\Certificate;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\ServerTlsContext;
use League\Uri\Components\Query;
use Psr\Log\NullLogger;
use function Amp\delay;

class SocketClientTest extends AsyncTestCase
{
    public function startServer(callable $handler): array
    {
        $handler = new ClosureRequestHandler($handler);
        $tlsContext = (new ServerTlsContext)->withDefaultCertificate(new Certificate(\dirname(__DIR__) . "/server.pem"));

        $servers = [
            Socket\listen(
                '127.0.0.1:0',
                (new Socket\BindContext)->withTlsContext($tlsContext)
            ),
        ];

        $options = (new Options)->withDebugMode();

        $server = new HttpSocketServer($servers, new NullLogger, $options);
        $server->start($handler);

        return [$servers[0]->getAddress()->toString(), $server];
    }

    public function testTrivialHttpRequest(): void
    {
        [$address, $server] = $this->startServer(function (Request $req) {
            $this->assertEquals("GET", $req->getMethod());
            $this->assertEquals("/uri", $req->getUri()->getPath());
            $query = Query::createFromUri($req->getUri());
            $this->assertEquals(
                [["foo", "bar"], ["baz", "1"], ["baz", "2"]],
                \iterator_to_array($query->getIterator())
            );
            $this->assertEquals(["header"], $req->getHeaderArray("custom"));

            $data = \str_repeat("*", 100000);
            $stream = new ReadableBuffer("data/" . $data . "/data");

            $res = new Response(Status::OK, [], $stream);

            $res->setCookie(new ResponseCookie("cookie", "with-value"));
            $res->setHeader("custom", "header");

            return $res;
        });

        $connector = new class implements Socket\SocketConnector {
            public function connect(
                string $uri,
                ?ConnectContext $context = null,
                ?Cancellation $token = null
            ): Socket\EncryptableSocket {
                $context = (new Socket\ConnectContext)
                    ->withTlsContext((new ClientTlsContext(''))->withoutPeerVerification());

                return (new Socket\DnsSocketConnector())->connect($uri, $context, $token);
            }
        };

        $client = (new HttpClientBuilder)
            ->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory($connector)))
            ->build();

        $port = \parse_url($address, PHP_URL_PORT);
        $request = new ClientRequest("https://localhost:$port/uri?foo=bar&baz=1&baz=2", "GET");
        $request->setHeader("custom", "header");

        $response = $client->request($request);
        self::assertEquals(200, $response->getStatus());
        self::assertEquals(["header"], $response->getHeaderArray("custom"));
        $body = $response->getBody()->buffer();
        self::assertEquals("data/" . \str_repeat("*", 100000) . "/data", $body);

        $server->stop();
    }

    public function testClientDisconnect(): void
    {
        [$address, $server] = $this->startServer(function (Request $req) use (&$server) {
            $this->assertEquals("POST", $req->getMethod());
            $this->assertEquals("/", $req->getUri()->getPath());
            $this->assertEquals([], $req->getAttributes());
            $this->assertEquals("body", $req->getBody()->buffer());

            $data = "data";
            $data .= \str_repeat("_", $server->getOptions()->getOutputBufferSize() + 1);

            return new Response(Status::OK, [], $data);
        });

        $port = \parse_url($address, PHP_URL_PORT);
        $context = (new Socket\ConnectContext)
            ->withTlsContext((new ClientTlsContext(''))->withoutPeerVerification());

        $socket = Socket\connect("tcp://localhost:$port/", $context);
        $socket->setupTls();

        $request = "POST / HTTP/1.0\r\nHost: localhost\r\nConnection: close\r\nContent-Length: 4\r\n\r\nbody";
        $socket->write($request);

        $socket->close();

        delay(0.1);

        $server->stop();
    }
}

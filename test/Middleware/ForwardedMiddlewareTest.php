<?php declare(strict_types=1);

namespace Amp\Http\Server\Test\Middleware;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Middleware\Forwarded;
use Amp\Http\Server\Middleware\ForwardedHeaderType;
use Amp\Http\Server\Middleware\ForwardedMiddleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket\InternetAddress;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\UriInterface as PsrUri;

class ForwardedMiddlewareTest extends AsyncTestCase
{
    private function createClient(string $address): Client&MockObject
    {
        $client = $this->createMock(Client::class);
        $client->method('getRemoteAddress')
            ->willReturn(new InternetAddress($address, 12345));

        return $client;
    }

    /**
     * @param \Closure(Forwarded|null):void $verifier
     */
    private function verifyUsing(\Closure $verifier): RequestHandler
    {
        return new class($verifier) implements RequestHandler {
            public function __construct(private readonly \Closure $verifier)
            {
            }

            public function handleRequest(Request $request): Response
            {
                ($this->verifier)($request->getAttribute(Forwarded::class));
                return new Response();
            }
        };
    }

    public function provideForwardedHeaders(): iterable
    {
        yield [
            ForwardedHeaderType::Forwarded,
            'For="[2001:db8:cafe::17]:4711"',
            new InternetAddress('2001:db8:cafe::17', 4711),
            [
                'for' => '[2001:db8:cafe::17]:4711',
            ],
        ];

        yield [
            ForwardedHeaderType::Forwarded,
            'for="[2001:db8:cafe::17]";proto=https;secret=test;by=172.18.0.9',
            new InternetAddress('2001:db8:cafe::17', 0),
            [
                'for' => '[2001:db8:cafe::17]',
                'proto' => 'https',
                'secret' => 'test',
                'by' => '172.18.0.9',
            ],
        ];

        yield [
            ForwardedHeaderType::Forwarded,
            'for=192.0.2.60;proto=http;by=203.0.113.43',
            new InternetAddress('192.0.2.60', 0),
            [
                'for' => '192.0.2.60',
                'proto' => 'http',
                'by' => '203.0.113.43',
            ],
        ];

        yield [
            ForwardedHeaderType::Forwarded,
            'for=192.0.2.43, for=198.51.100.17',
            new InternetAddress('198.51.100.17', 0),
            [
                'for' => '198.51.100.17',
            ],
        ];

        yield [
            ForwardedHeaderType::Forwarded,
            'for="2001:db8:cafe::17"',
            null,
        ];

        yield [
            ForwardedHeaderType::XForwardedFor,
            '2001:db8:85a3:8d3:1319:8a2e:370:7348',
            new InternetAddress('2001:db8:85a3:8d3:1319:8a2e:370:7348', 0),
        ];

        yield [
            ForwardedHeaderType::XForwardedFor,
            '203.0.113.195,2001:db8:85a3:8d3:1319:8a2e:370:7348,150.172.238.178',
            new InternetAddress('150.172.238.178', 0),
        ];

        yield [
            ForwardedHeaderType::XForwardedFor,
            '2001:db8:85a3:8d3:1319:8a2e:370',
            null,
        ];
    }

    /**
     * @dataProvider provideForwardedHeaders
     */
    public function testForwarded(
        ForwardedHeaderType $type,
        string $headerValue,
        ?InternetAddress $address,
        array $fields = [],
    ): void {
        $middleware = new ForwardedMiddleware($type, ['172.18.0.0/24']);

        $request = new Request($this->createClient('172.18.0.5'), 'GET', $this->createMock(PsrUri::class));
        $request->setHeader($type->getHeaderName(), $headerValue);

        $middleware->handleRequest($request, $this->verifyUsing(function (?Forwarded $forwarded) use (
            $address,
            $fields
        ): void {
            self::assertSame($address?->getAddress(), $forwarded?->getFor()->getAddress());
            self::assertSame($address?->getPort(), $forwarded?->getFor()->getPort());

            if (!$forwarded) {
                return;
            }

            foreach ($fields as $field => $expected) {
                self::assertSame($expected, $forwarded->getField($field));
            }
        }));
    }
}

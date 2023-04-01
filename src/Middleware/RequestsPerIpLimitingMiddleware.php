<?php

namespace Amp\Http\Server\Middleware;

use Amp\Http\Server\ClientException;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Socket\InternetAddress;
use Amp\Socket\InternetAddressVersion;
use Amp\Socket\SocketAddress;
use Psr\Log\LoggerInterface as PsrLogger;

final class RequestsPerIpLimitingMiddleware implements Middleware
{
    /** @var array<string, int> */
    private array $requestsPerIp = [];

    public function __construct(
        private readonly PsrLogger $logger,
        private readonly int $requestsPerIpLimit = 100,
    ) {
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        /** @var SocketAddress $clientIp */
        $address = $request->hasAttribute(ForwardedForMiddleware::class)
            ? $request->getAttribute(ForwardedForMiddleware::class)
            : $request->getClient()->getRemoteAddress();

        if (!$address instanceof InternetAddress) {
            return $requestHandler->handleRequest($request);
        }

        $ip = $address->getAddress();
        $bytes = $address->getAddressBytes();

        // Connections on localhost are excluded from the connections per IP setting.
        // Checks IPv4 loopback (127.x), IPv6 loopback (::1) and IPv4-to-IPv6 mapped loopback.
        if ($ip === "::1" || \str_starts_with($ip, "127.")
            || \str_starts_with($bytes, "\0\0\0\0\0\0\0\0\0\0\xff\xff\x7f")
        ) {
            return $requestHandler->handleRequest($request);
        }

        if ($address->getVersion() === InternetAddressVersion::IPv6) {
            $bytes = \substr($bytes, 0, 7 /* /56 block for IPv6 */);
        }

        $this->requestsPerIp[$bytes] ??= 0;

        if ($this->requestsPerIp[$bytes] >= $this->requestsPerIpLimit) {
            if (isset($bytes[4])) {
                $ip .= "/56";
            }

            $this->logger->warning(
                "Client request denied: too many existing requests from {$ip}",
                ['local' => $request->getClient()->getLocalAddress()->toString(), 'remote' => $address->toString()],
            );

            throw new ClientException($request->getClient(), 'Request denied: too many existing requests');
        }

        ++$this->requestsPerIp[$bytes];

        try {
            return $requestHandler->handleRequest($request);
        } finally {
            --$this->requestsPerIp[$bytes];
        }
    }
}

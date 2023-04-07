<?php

namespace Amp\Http\Server\Middleware;

use Amp\Cache\LocalCache;
use Amp\Http;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Socket\CidrMatcher;
use Amp\Socket\InternetAddress;

final class ForwardedForMiddleware implements Middleware
{
    /** @var list<CidrMatcher> */
    private readonly array $trustedProxies;

    /** @var LocalCache<bool> */
    private readonly LocalCache $trustedIps;

    /**
     * @param array<non-empty-string> $trustedProxies Array of IPv4 or IPv6 addresses with an optional subnet mask.
     *      e.g., '172.18.0.0/24'
     */
    public function __construct(
        private readonly ForwardedForHeaderType $headerType,
        array $trustedProxies,
        int $cacheSize = 1000
    ) {
        $this->trustedProxies = \array_map(
            static fn (string $ip) => new CidrMatcher($ip),
            \array_values($trustedProxies),
        );

        $this->trustedIps = new LocalCache($cacheSize);
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $clientAddress = $request->getClient()->getRemoteAddress();

        if ($clientAddress instanceof InternetAddress && $this->isTrustedProxy($clientAddress)) {
            $request->setAttribute(self::class, match ($this->headerType) {
                ForwardedForHeaderType::FORWARDED => $this->getForwarded($request),
                ForwardedForHeaderType::X_FORWARDED_FOR => $this->getForwardedFor($request),
            });
        }

        return $requestHandler->handleRequest($request);
    }

    private function isTrustedProxy(InternetAddress $address): bool
    {
        $ip = $address->getAddress();
        $trusted = $this->trustedIps->get($ip);
        if ($trusted !== null) {
            return $trusted;
        }

        $trusted = false;
        foreach ($this->trustedProxies as $matcher) {
            if ($matcher->match($ip)) {
                $trusted = true;
                break;
            }
        }

        $this->trustedIps->set($ip, $trusted);

        return $trusted;
    }

    private function getForwarded(Request $request): ?ForwardedFor
    {
        $headers = Http\parseMultipleHeaderFields($request, 'forwarded');
        if (!$headers) {
            return null;
        }

        foreach (\array_reverse($headers) as $header) {
            $for = $header['for'] ?? null;
            if ($for === null) {
                continue;
            }

            if (!\str_contains($for, ':') || \str_ends_with($for, ']')) {
                $for .= ':0';
            }

            $address = InternetAddress::tryFromString($for);
            if (!$address || $this->isTrustedProxy($address)) {
               continue;
            }

            return new ForwardedFor($address, $header);
        }

        return null;
    }

    private function getForwardedFor(Request $request): ?ForwardedFor
    {
        $forwardedFor = Http\splitHeader($request, 'x-forwarded-for');
        if (!$forwardedFor) {
            return null;
        }

        $forwardedFor = \array_map(static function (string $ip): string {
            if (\str_contains($ip, ':')) {
                return '[' . \trim($ip, '[]') . ']:0';
            }

            return $ip . ':0';
        }, $forwardedFor);

        /** @var InternetAddress[] $forwardedFor */
        $forwardedFor = \array_filter(\array_map(InternetAddress::tryFromString(...), $forwardedFor));
        foreach (\array_reverse($forwardedFor) as $address) {
            if (!$this->isTrustedProxy($address)) {
                return new ForwardedFor($address, []);
            }
        }

        return null;
    }
}

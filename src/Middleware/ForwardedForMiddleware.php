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

    /** @var LocalCache<non-empty-string, bool> */
    private readonly LocalCache $trustedIps;

    /**
     * @param array<non-empty-string> $trustedProxies Array of IPv4 or IPv6 addresses with an optional subnet mask.
     *      e.g., '172.18.0.0/24'
     */
    public function __construct(array $trustedProxies, int $cacheSize = 1000)
    {
        $this->trustedProxies = \array_map(
            static fn (string $ip) => new CidrMatcher($ip),
            \array_values($trustedProxies),
        );

        $this->trustedIps = new LocalCache($cacheSize);
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $request->setAttribute(self::class, []);

        $clientAddress = $request->getClient()->getRemoteAddress();
        if (!$clientAddress instanceof InternetAddress) {
            return $requestHandler->handleRequest($request);
        }

        if ($this->isTrusted($clientAddress)) {
            $request->setAttribute(self::class, $this->getForwarded($request));
        }

        return $requestHandler->handleRequest($request);
    }

    private function isTrusted(InternetAddress $address): bool
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

    /**
     * @return list<non-empty-string>
     */
    private function getForwarded(Request $request): array
    {
        $pairs = Http\parseFieldValueComponents($request, 'forwarded');
        if (!$pairs) {
            $forwardedFor = $request->getHeaderArray('x-forwarded-for');
            if (!$forwardedFor) {
                return [];
            }

            $forwardedFor = \array_map(\trim(...), \explode(',', \implode(',', $forwardedFor)));
            return \array_filter($forwardedFor, static fn (string $ip) => \filter_var($ip, \FILTER_VALIDATE_IP));
        }

        $ips = [];
        foreach ($pairs as [$key, $value]) {
            if ($key !== 'for' || !\filter_var($value, \FILTER_VALIDATE_IP)) {
                continue;
            }

            $ips[] = $value;
        }

        return $ips;
    }
}

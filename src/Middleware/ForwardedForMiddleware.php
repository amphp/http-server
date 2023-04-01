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
        $clientAddress = $request->getClient()->getRemoteAddress();

        if ($clientAddress instanceof InternetAddress && $this->isTrusted($clientAddress)) {
            $request->setAttribute(self::class, $this->getForwarded($request));
        } else {
            $request->setAttribute(self::class, []);
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
     * @return list<ForwardedFor>
     */
    private function getForwarded(Request $request): array
    {
        $maps = Http\parseFieldValueComponents($request, 'forwarded');
        if (!$maps) {
            $forwardedFor = $request->getHeaderArray('x-forwarded-for');
            if (!$forwardedFor) {
                return [];
            }

            $forwardedFor = \array_map(\trim(...), \explode(',', \implode(',', $forwardedFor)));
            $forwardedFor = \array_values(\array_filter(\array_map($this->tryInternetAddress(...), $forwardedFor)));
            return \array_map(static fn (InternetAddress $address) => new ForwardedFor($address, []), $forwardedFor);
        }

        $forwardedFor = [];
        foreach ($maps as $map) {
            foreach ($map as $key => $value) {
                if ($key !== 'for') {
                    continue;
                }

                if ($value === null) {
                    break;
                }

                $address = $this->tryInternetAddress($value);
                if (!$address) {
                   break;
                }

                $forwardedFor[] = new ForwardedFor($address, $map);
                break;
            }
        }

        return $forwardedFor;
    }

    private function tryInternetAddress(string $value): ?InternetAddress
    {
        if (!\str_contains($value, ':') || \str_ends_with($value, ']')) {
            $value .= ':0';
        }

        $colon = \strrpos($value, ':');
        \assert($colon !== false);

        $ip = \substr($value, 0, $colon);
        $port = (int) \substr($value, $colon + 1);
        if (\str_contains($ip, ':')) {
            $ip = \trim($ip, '[]');
        }

        if (!\filter_var($ip, \FILTER_VALIDATE_IP)) {
            return null;
        }

        return new InternetAddress($ip, $port);
    }
}

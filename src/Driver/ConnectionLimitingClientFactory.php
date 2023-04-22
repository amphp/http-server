<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver;

use Amp\Socket\InternetAddress;
use Amp\Socket\InternetAddressVersion;
use Amp\Socket\Socket;
use Psr\Log\LoggerInterface as PsrLogger;

final class ConnectionLimitingClientFactory implements ClientFactory
{
    /** @var array<string, int> */
    private array $clientsPerIp = [];

    public function __construct(
        private readonly ClientFactory $clientFactory,
        private readonly PsrLogger $logger,
        private readonly int $connectionsPerIpLimit,
    ) {
    }

    public function createClient(Socket $socket): ?Client
    {
        $address = $socket->getRemoteAddress();

        if (!$address instanceof InternetAddress) {
            return $this->clientFactory->createClient($socket);
        }

        $ip = $address->getAddress();
        $bytes = $address->getAddressBytes();

        // Connections on localhost are excluded from the connections per IP setting.
        // Checks IPv4 loopback (127.x), IPv6 loopback (::1) and IPv4-to-IPv6 mapped loopback.
        if ($ip === "::1" || \str_starts_with($ip, "127.")
            || \str_starts_with($bytes, "\0\0\0\0\0\0\0\0\0\0\xff\xff\x7f")
        ) {
            return $this->clientFactory->createClient($socket);
        }

        if ($address->getVersion() === InternetAddressVersion::IPv6) {
            $bytes = \substr($bytes, 0, 7 /* /56 block for IPv6 */);
        }

        $this->clientsPerIp[$bytes] ??= 0;

        if ($this->clientsPerIp[$bytes] >= $this->connectionsPerIpLimit) {
            if (isset($bytes[4])) {
                $ip .= "/56";
            }

            $this->logger->warning(
                "Client denied: too many existing connections from {$ip}",
                ['local' => $socket->getLocalAddress()->toString(), 'remote' => $address->toString()],
            );

            return null;
        }

        ++$this->clientsPerIp[$bytes];

        $client = $this->clientFactory->createClient($socket);
        if ($client === null) {
            $this->onClose($bytes);

            return null;
        }

        $socket->onClose(fn () => $this->onClose($bytes));

        return $client;
    }

    private function onClose(string $bytes): void
    {
        if (--$this->clientsPerIp[$bytes] === 0) {
            unset($this->clientsPerIp[$bytes]);
        }
    }
}

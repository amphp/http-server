<?php

namespace Amp\Http\Server\Driver;

use Amp\CancelledException;
use Amp\Socket\EncryptableSocket;
use Amp\TimeoutCancellation;
use Psr\Log\LoggerInterface as PsrLogger;

final class SocketClientFactory implements ClientFactory
{
    public function __construct(
        private readonly PsrLogger $logger,
        private readonly float $tlsHandshakeTimeout = 5,
    ) {
    }

    public function createClient(EncryptableSocket $socket): ?Client
    {
        \assert($this->logger?->debug(\sprintf(
                "Accepted %s on %s",
                $socket->getRemoteAddress()->toString(),
                $socket->getLocalAddress()->toString(),
            )) || true);

        if ($socket->isTlsAvailable()) {
            try {
                $socket->setupTls(new TimeoutCancellation($this->tlsHandshakeTimeout));

                \assert($this->logger->debug(\sprintf(
                        "TLS negotiated with %s (%s with %s, application protocol: %s)",
                        $socket->getRemoteAddress()->toString(),
                        $socket->getTlsInfo()->getVersion(),
                        $socket->getTlsInfo()->getCipherName(),
                        $socket->getTlsInfo()->getApplicationLayerProtocol() ?? "none",
                    )) || true);
            } catch (CancelledException) {
                $this->logger->debug(\sprintf(
                    "TLS negotiation timed out with %s",
                    $socket->getRemoteAddress()->toString(),
                ));

                return null;
            }
        }

        return new SocketClient($socket);
    }
}

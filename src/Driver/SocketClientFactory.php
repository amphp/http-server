<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver;

use Amp\CancelledException;
use Amp\Socket\Socket;
use Amp\Socket\SocketException;
use Amp\TimeoutCancellation;
use Psr\Log\LoggerInterface as PsrLogger;

final class SocketClientFactory implements ClientFactory
{
    public function __construct(
        private readonly PsrLogger $logger,
        private readonly float $tlsHandshakeTimeout = 5,
    ) {
    }

    public function createClient(Socket $socket): ?Client
    {
        $local = $socket->getLocalAddress()->toString();
        $remote = $socket->getRemoteAddress()->toString();

        /** @psalm-suppress RedundantCondition */
        \assert($this->logger->debug(\sprintf("Accepted %s on %s", $remote, $local)) || true);

        if ($socket->isTlsConfigurationAvailable()) {
            try {
                $socket->setupTls(new TimeoutCancellation($this->tlsHandshakeTimeout));

                /** @psalm-suppress RedundantCondition, PossiblyNullReference */
                \assert($this->logger->debug(\sprintf(
                    "TLS negotiated with %s (%s with %s, application protocol: %s)",
                    $remote,
                    $socket->getTlsInfo()->getVersion(),
                    $socket->getTlsInfo()->getCipherName(),
                    $socket->getTlsInfo()->getApplicationLayerProtocol() ?? "none",
                )) || true);
            } catch (SocketException $exception) {
                $message = $exception->getMessage();
                $this->logger->warning(
                    \sprintf("TLS negotiation failed for %s on %s: %s", $remote, $local, $message),
                    ['local' => $local, 'remote' => $remote, 'message' => $message],
                );

                return null;
            } catch (CancelledException) {
                $this->logger->warning(
                    \sprintf("TLS negotiation timed out for %s on %s", $remote, $local),
                    ['local' => $local, 'remote' => $remote],
                );

                return null;
            }
        }

        return new SocketClient($socket);
    }
}

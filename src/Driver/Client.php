<?php

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\Options;
use Amp\Promise;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;

interface Client
{
    public const CLOSED_RD = 1;
    public const CLOSED_WR = 2;
    public const CLOSED_RDWR = 3;

    /**
     * Listen for requests on the client and parse them using the given HTTP driver.
     *
     * @param HttpDriverFactory $driverFactory
     *
     * @throws \Error If the client has already been started.
     */
    public function start(HttpDriverFactory $driverFactory): void;

    /**
     * @return Options Server options object.
     */
    public function getOptions(): Options;

    /**
     * @return int Number of requests being read.
     */
    public function getPendingRequestCount(): int;

    /**
     * @return int Number of requests with pending responses.
     */
    public function getPendingResponseCount(): int;

    /**
     * @return bool `true` if the number of pending responses is greater than the number of pending requests.
     *     Useful for determining if a request handler is actively writing a response or if a request is taking too
     *     long to arrive.
     */
    public function isWaitingOnResponse(): bool;

    /**
     * Integer ID of this client.
     *
     * @return int
     */
    public function getId(): int;

    /**
     * @return SocketAddress Remote client address.
     */
    public function getRemoteAddress(): SocketAddress;

    /**
     * @return SocketAddress Local server address.
     */
    public function getLocalAddress(): SocketAddress;

    /**
     * @return bool `true` if the client is connected via a unix socket
     */
    public function isUnix(): bool;

    /**
     * @return bool `true` if the client is encrypted, `false` if plaintext.
     */
    public function isEncrypted(): bool;

    /**
     * If the client is encrypted a TlsInfo object is returned, otherwise null.
     *
     * @return TlsInfo|null
     */
    public function getTlsInfo(): ?TlsInfo;

    /**
     * @return bool `true` if the client has been exported from the server using `Response::detach()`.
     */
    public function isExported(): bool;

    /**
     * @return int Integer mask of `Client::CLOSED_*` constants.
     */
    public function getStatus(): int;

    /**
     * @return int Timestamp when the client will automatically be closed. This timestamp is updated when there
     *             is activity on the connect that should extend the timeout.
     */
    public function getExpirationTime(): int;

    /**
     * @param int $expiresAt Timestamp when the client should be automatically closed if there is no further activity
     *                       from the client.
     */
    public function updateExpirationTime(int $expiresAt): void;

    /**
     * Attaches a callback invoked with this client closes. The callback is passed this object as the first parameter.
     *
     * @param callable $callback
     */
    public function onClose(callable $callback): void;

    /**
     * Forcefully closes the client connection.
     */
    public function close(): void;

    /**
     * Gracefully close the client, responding to any pending requests before closing the connection.
     *
     * @param int $timeout Number of milliseconds before the connection is forcefully closed.
     *
     * @return Promise Resolved once any pending responses have been sent to the client.
     */
    public function stop(int $timeout): Promise;
}

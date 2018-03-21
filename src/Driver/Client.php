<?php

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\Options;
use Amp\Promise;

interface Client {
    const CLOSED_RD = 1;
    const CLOSED_WR = 2;
    const CLOSED_RDWR = 3;

    /**
     * Listen for requests on the client and parse them using the given HTTP driver.
     *
     * @param HttpDriverFactory $driverFactory
     *
     * @throws \Error If the client has already been started.
     */
    public function start(HttpDriverFactory $driverFactory);

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
     * @return string Remote IP address or unix socket path.
     */
    public function getRemoteAddress(): string;

    /**
     * @return int|null Remote port number or `null` for unix sockets.
     */
    public function getRemotePort(); /* : ?int */

    /**
     * @return string Local server IP address or unix socket path.
     */
    public function getLocalAddress(): string;

    /**
     * @return int|null Local server port or `null` for unix sockets.
     */
    public function getLocalPort(); /* : ?int */

    /**
     * @return bool `true` if this client is connected via an unix domain socket.
     */
    public function isUnix(): bool;

    /**
     * @return bool `true` if the client is encrypted, `false` if plaintext.
     */
    public function isEncrypted(): bool;

    /**
     * If the client is encrypted, returns the array returned from stream_get_meta_data($this->socket)["crypto"].
     * Otherwise returns an empty array.
     *
     * @return array
     */
    public function getCryptoContext(): array;

    /**
     * @return bool `true` if the client has been exported from the server using `Response::detach()`.
     */
    public function isExported(): bool;

    /**
     * @return int Integer mask of `Client::CLOSED_*` constants.
     */
    public function getStatus(): int;

    /**
     * Attaches a callback invoked with this client closes. The callback is passed this object as the first parameter.
     *
     * @param callable $callback
     */
    public function onClose(callable $callback);

    /**
     * Forcefully closes the client connection.
     */
    public function close();

    /**
     * Gracefully close the client, responding to any pending requests before closing the connection.
     *
     * @param int $timeout Number of milliseconds before the connection is forcefully closed.
     *
     * @return \Amp\Promise Resolved once any pending responses have been sent to the client.
     */
    public function stop(int $timeout): Promise;
}

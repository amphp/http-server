<?php

namespace Amp\Http\Server;

interface Client {
    const CLOSED_RD = 1;
    const CLOSED_WR = 2;
    const CLOSED_RDWR = 3;

    /**
     * Listen for requests on the client and parse them using the given HTTP driver.
     *
     * @param \Amp\Http\Server\HttpDriverFactory $driverFactory
     *
     * @throws \Error If the client has already been started.
     */
    public function start(HttpDriverFactory $driverFactory);

    /**
     * @return \Amp\Http\Server\Options Server options object.
     */
    public function getOptions(): Options;

    /**
     * @return int Number of requests with pending responses.
     */
    public function pendingResponseCount(): int;

    /**
     * @return int Number of requests being read.
     */
    public function pendingRequestCount(): int;

    /**
     * @return bool `true` if the number of pending responses is greater than the number of pending requests.
     *     Useful for determining if a responder is actively writing a response or if a request is taking too
     *     long to arrive.
     */
    public function waitingOnResponse(): bool;

    /**
     * Integer ID of this client.
     *
     * @return int
     */
    public function getId(): int;

    /**
     * @return string Remote IP address.
     */
    public function getRemoteAddress(): string;

    /**
     * @return int Remote port number.
     */
    public function getRemotePort(): int;

    /**
     * @return string Local server IP address.
     */
    public function getLocalAddress(): string;

    /**
     * @return int Local server port.
     */
    public function getLocalPort(): int;

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
     * @return bool `true` if the client has been exported from the server using Response::detach().
     */
    public function isExported(): bool;

    /**
     * @return string Unique network ID based on IP for matching the client with other clients from the same IP.
     */
    public function getNetworkId(): string;

    /**
     * @return int Integer mask of Client::CLOSED_* constants.
     */
    public function getStatus(): int;

    /**
     * Forcefully closes the client connection.
     */
    public function close();

    /**
     * Attaches a callback invoked with this client closes. The callback is passed this object as the first parameter.
     *
     * @param callable $callback
     */
    public function onClose(callable $callback);
}

<?php

namespace Aerys;

final class Options {
    /** @var \Aerys\Internal\Options */
    private $values;

    public function __construct() {
        $this->values = new Internal\Options;
    }

    public function __clone() {
        $this->values = clone $this->values;
    }

    /**
     * @return bool True if server is in debug mode, false if in production mode.
     */
    public function isInDebugMode(): bool {
        return $this->values->debug;
    }

    /**
     * @param bool $flag True for debug mode, false for production mode.
     *
     * @return \Aerys\Options
     */
    public function withDebugMode(bool $flag): self {
        $new = clone $this;
        $new->values->debug = $flag;
        return $new;
    }

    /**
     * @return string|null
     */
    public function getUser() { /* : ?string */
        return $this->values->user;
    }

    /**
     * @param string|null $user Username to run server or null to not attempt to switch users. Default is null.
     *
     * @return \Aerys\Options
     */
    public function withUser(string $user = null): self {
        $new = clone $this;
        $new->values->user = $user;
        return $new;
    }

    /**
     * @return int The maximum number of connections that can be handled by the server at a single time.
     */
    public function getMaxConnections(): int {
        return $this->values->maxConnections;
    }

    /**
     * @param int $count Maximum number of connections the server should accept at one time. Default is 10000.
     *
     * @return \Aerys\Options
     *
     * @throws \Error If count is less than 1.
     */
    public function withMaxConnections(int $count): self {
        if ($count < 1) {
            throw new \Error(
                "Max connections setting must be greater than or equal to one"
            );
        }

        $new = clone $this;
        $new->values->maxConnections = $count;
        return $new;
    }

    /**
     * @return int The maximum number of connections allowed from a single IP.
     */
    public function getMaxConnectionsPerIp(): int {
        return $this->values->connectionsPerIP;
    }

    /**
     * @param int $count Maximum number of connections to allow from a single IP address. Default is 30.
     *
     * @throws \Error If the count is less than 1.
     */
    public function withMaxConnectionsPerIp(int $count): self {
        if ($count < 1) {
            throw new \Error(
                "Connections per IP maximum must be greater than or equal to one"
            );
        }

        $this->values->connectionsPerIP = $count;
    }

    /**
     * @return int The maximum number of requests that can be made on a single connection.
     */
    public function getMaxRequestsPerConnection(): int {
        return $this->values->maxRequestsPerConnection;
    }

    /**
     * @param int $count Maximum number of requests that can be made on a single connection. Default is 1000.
     *
     * @return \Aerys\Options
     *
     * @throws \Error If the count is less than 1.
     */
    public function withMaxRequestsPerConnection(int $count): self {
        if ($count < 1) {
            throw new \Error(
                "Maximum number of requests on a connection must be greater than zero. Set it to PHP_INT_MAX to allow an unlimited amount of requests"
            );
        }

        $new = clone $this;
        $new->values->maxRequestsPerConnection = $count;
        return $new;
    }

    /**
     * @return int Number of seconds a connection may be idle before it is automatically closed.
     */
    public function getConnectionTimeout(): int {
        return $this->values->connectionTimeout;
    }

    /**
     * @param int $seconds Number of seconds a connection may be idle before it is automatically closed. Default is 15.
     *
     * @return \Aerys\Options
     *
     * @throws \Error If the number of seconds is less than 1.
     */
    public function withConnectionTimeout(int $seconds): self {
        if ($seconds < 1) {
            throw new \Error(
                "Keep alive timeout setting must be greater than or equal to one second"
            );
        }

        $new = clone $this;
        $new->values->connectionTimeout = $seconds;
        return $new;
    }

    /**
     * @return int Maximum backlog size of each listening server socket.
     */
    public function getSocketBacklogSize(): int {
        return $this->values->socketBacklogSize;
    }

    /**
     * @param int $backlog Maximum backlog size of each listening server socket. Default is 128.
     *
     * @return \Aerys\Options
     *
     * @throws \Error If the backlog size is less than 16.
     */
    public function withSocketBacklogSize(int $backlog): self {
        if ($backlog < 16) {
            throw new \Error(
                "Socket backlog size setting must be greater than or equal to 16"
            );
        }

        $new = clone $this;
        $new->values->socketBacklogSize = $backlog;
        return $new;
    }

    /**
     * @return bool True to UPPERCASE all request method strings, false uses case as given in client request.
     */
    public function shouldNormalizeMethodCase(): bool {
        return $this->values->normalizeMethodCase;
    }

    /**
     * @param bool $flag True to UPPERCASE all request method strings, false uses case as given in client request.
     *     Defaults to true.
     *
     * @return \Aerys\Options
     */
    public function withNormalizeMethodCase(bool $flag): self {
        $new = clone $this;
        $new->values->normalizeMethodCase = $flag;
        return $new;
    }

    /**
     * @return int Maximum request body size in bytes.
     */
    public function getMaxBodySize(): int {
        return $this->values->maxBodySize;
    }

    /**
     * @param int $bytes Default maximum request body size in bytes. Individual requests may be increased by calling
     *     Request::getBody($newMaximum). Default is 131072 (128k).
     *
     * @return \Aerys\Options
     *
     * @throws \Error If the number of bytes is less than 0.
     */
    public function withMaxBodySize(int $bytes): self {
        if ($bytes < 0) {
            throw new \Error(
                "Max body size setting must be greater than or equal to zero"
            );
        }

        $new = clone $this;
        $new->values->maxBodySize = $bytes;
        return $new;
    }

    /**
     * @return int Maximum size of the request header section in bytes.
     */
    public function getMaxHeaderSize(): int {
        return $this->values->maxHeaderSize;
    }

    /**
     * @param int $bytes Maximum size of the request header section in bytes. Default is 32768 (32k).
     *
     * @return \Aerys\Options
     * @throws \Error
     */
    public function withMaxHeaderSize(int $bytes): self {
        if ($bytes <= 0) {
            throw new \Error(
                "Max header size setting must be greater than zero"
            );
        }

        $new = clone $this;
        $new->values->maxHeaderSize = $bytes;
        return $new;
    }

    /**
     * @return int
     */
    public function getSoftStreamCap(): int {
        return $this->values->softStreamCap;
    }

    /**
     * @param int $bytes Default is 131072 (128k).
     *
     * @return \Aerys\Options
     * @throws \Error
     */
    public function withSoftStreamCap(int $bytes): self {
        if ($bytes < 0) {
            throw new \Error(
                "Soft stream cap setting must be greater than or equal to zero"
            );
        }

        $new = clone $this;
        $new->values->softStreamCap = $bytes;
        return $new;
    }

    /**
     * @return int Maximum number of concurrent HTTP/2 streams.
     */
    public function getMaxConcurrentStreams(): int {
        return $this->values->maxConcurrentStreams;
    }

    /**
     * @param int $streams Maximum number of concurrent HTTP/2 streams. Default is 20.
     *
     * @return \Aerys\Options
     *
     * @throws \Error If the stream count is less than 1.
     */
    public function withMaxConcurrentStreams(int $streams): self {
        if ($streams < 1) {
            throw new \Error(
                "Max number of concurrent streams setting must be greater than zero"
            );
        }

        $new = clone $this;
        $new->values->maxConcurrentStreams = $streams;
        return $new;
    }

    /**
     * @return int Maximum number of HTTP/2 frames per second.
     */
    public function getMaxFramesPerSecond(): int {
        return $this->values->maxFramesPerSecond;
    }

    /**
     * @param int $frames Maximum number of HTTP/2 frames per second. Default is 60.
     *
     * @return \Aerys\Options
     *
     * @throws \Error If the frame count is less than 1.
     */
    public function withMaxFramesPerSecond(int $frames): self {
        if ($frames < 1) {
            throw new \Error(
                "Max number of HTTP/2 frames per second setting must be greater than zero"
            );
        }

        $new = clone $this;
        $new->values->maxFramesPerSecond = $frames;
        return $new;
    }

    /**
     * @return int The maximum number of bytes to read from a client per read.
     */
    public function getIoGranularity(): int {
        return $this->values->ioGranularity;
    }

    /**
     * @param int $bytes The maximum number of bytes to read from a client per read. Larger numbers are better for
     *     performance but can increase memory usage. Minimum recommended is 16384 (16k), default is 32768 (32k).
     *
     * @return \Aerys\Options
     *
     * @throws \Error If the number of bytes is less than 1.
     */
    public function withIoGranularity(int $bytes): self {
        if ($bytes < 1) {
            throw new \Error(
                "IO granularity setting must be greater than zero"
            );
        }

        $new = clone $this;
        $new->values->ioGranularity = $bytes;
        return $new;
    }

    /**
     * @return string[] An array of allowed request methods.
     */
    public function getAllowedMethods(): array {
        if ($this->values->normalizeMethodCase) {
            return \array_unique(\array_map("strtoupper", $this->values->allowedMethods));
        }

        return $this->values->allowedMethods;
    }

    /**
     * @param string[] $allowedMethods An array of allowed request methods. Default is GET, POST, PUT, PATCH, HEAD,
     *     OPTIONS, DELETE.
     *
     * @return \Aerys\Options
     *
     * @throws \Error If the array contains non-strings, empty method names, or does not contain GET or HEAD.
     */
    public function withAllowedMethods(array $allowedMethods): self {
        foreach ($allowedMethods as $key => $method) {
            if (!\is_string($method)) {
                throw new \Error(
                    \sprintf(
                        "Invalid type at key %s of allowed methods array: %s",
                        $key,
                        \is_object($method) ? \get_class($method) : \gettype($method)
                    )
                );
            }

            if ($method === "") {
                throw new \Error(
                    "Invalid empty HTTP method at key {$key} of allowed methods array"
                );
            }
        }

        $allowedMethods = \array_unique($allowedMethods);

        if (!\in_array("GET", $allowedMethods, true)) {
            throw new \Error(
                "Servers must support GET as an allowed HTTP method"
            );
        }

        if (!\in_array("HEAD", $allowedMethods, true)) {
            throw new \Error(
                "Servers must support HEAD as an allowed HTTP method"
            );
        }

        $new = clone $this;
        $new->values->allowedMethods = $allowedMethods;
        return $new;
    }

    /**
     * @return int Number of body bytes to buffer before writes are made to the client.
     */
    public function getOutputBufferSize(): int {
        return $this->values->outputBufferSize;
    }

    /**
     * @param int $bytes Number of body bytes to buffer before writes are made to the client. Smaller numbers push data
     *     sooner, but decreases performance. Default is 8192 (8k).
     *
     * @return \Aerys\Options
     * @throws \Error
     */
    public function withOutputBufferSize(int $bytes): self {
        if ($bytes <= 0) {
            throw new \Error(
                "Output buffer size must be greater than zero bytes"
            );
        }

        $new = clone $this;
        $new->values->outputBufferSize = $bytes;
        return $new;
    }

    /**
     * @return int Number of milliseconds to wait when shutting down the server before forcefully shutting down.
     */
    public function getShutdownTimeout(): int {
        return $this->values->shutdownTimeout;
    }

    /**
     * @param int $milliseconds Number of milliseconds to wait when shutting down the server before forcefully shutting
     *     down. Default is 3000.
     *
     * @return \Aerys\Options
     *
     * @throws \Error If the timeout is less than 0.
     */
    public function withShutdownTimeout(int $milliseconds): self {
        if ($milliseconds < 0) {
            throw new \Error(
                "Shutdown timeout size must be greater than or equal to zero"
            );
        }

        $new = clone $this;
        $new->values->shutdownTimeout = $milliseconds;
        return $new;
    }

    /**
     * Returns an object with public properties corresponding to the values given in this object. Primarily for internal
     * use for better performance. May be used in external components if option values are frequently accessed.
     * Note that changing values in the returned object has no effect on the values used by the server.
     *
     * @return \Aerys\Internal\Options
     */
    public function export(): Internal\Options {
        $clone = clone $this->values;

        if ($clone->normalizeMethodCase) {
            $clone->allowedMethods = \array_unique(\array_map("strtoupper", $clone->allowedMethods));
        }

        return $clone;
    }
}

<?php

namespace Amp\Http\Server;

final class Options
{
    private $debug = false;
    private $connectionLimit = 10000;
    private $connectionsPerIpLimit = 30; // IPv4: /32, IPv6: /56 (per RFC 6177)
    private $http1Timeout = 15; // seconds
    private $http2Timeout = 60; // seconds
    private $tlsSetupTimeout = 5; // seconds

    private $concurrentStreamLimit = 256;

    private $allowedMethods = ["GET", "POST", "PUT", "PATCH", "HEAD", "OPTIONS", "DELETE"];

    private $bodySizeLimit = 131072;
    private $headerSizeLimit = 32768;
    private $chunkSize = 8192;
    private $streamThreshold = 8192;

    private $compression = true;
    private $allowHttp2Upgrade = false;
    private $pushEnabled = true;
    private $requestLogContext = false;

    /**
     * @return bool `true` if server is in debug mode, `false` if in production mode.
     */
    public function isInDebugMode(): bool
    {
        return $this->debug;
    }

    /**
     * Sets debug mode to `true`.
     *
     * @return self
     */
    public function withDebugMode(): self
    {
        $new = clone $this;
        $new->debug = true;

        return $new;
    }

    /**
     * Sets debug mode to `false`.
     *
     * @return self
     */
    public function withoutDebugMode(): self
    {
        $new = clone $this;
        $new->debug = false;

        return $new;
    }

    /**
     * @return int The maximum number of connections that can be handled by the server at a single time.
     */
    public function getConnectionLimit(): int
    {
        return $this->connectionLimit;
    }

    /**
     * @param int $count Maximum number of connections the server should accept at one time. Default is 10000.
     *
     * @return self
     *
     * @throws \Error If count is less than 1.
     */
    public function withConnectionLimit(int $count): self
    {
        if ($count < 1) {
            throw new \Error(
                "Connection limit setting must be greater than or equal to one"
            );
        }

        $new = clone $this;
        $new->connectionLimit = $count;

        return $new;
    }

    /**
     * @return int The maximum number of connections allowed from a single IP.
     */
    public function getConnectionsPerIpLimit(): int
    {
        return $this->connectionsPerIpLimit;
    }

    /**
     * @param int $count Maximum number of connections to allow from a single IP address. Default is 30.
     *
     * @return self
     *
     * @throws \Error If the count is less than 1.
     */
    public function withConnectionsPerIpLimit(int $count): self
    {
        if ($count < 1) {
            throw new \Error(
                "Connections per IP maximum must be greater than or equal to one"
            );
        }

        $new = clone $this;
        $new->connectionsPerIpLimit = $count;

        return $new;
    }

    /**
     * @return int Number of seconds an HTTP/1.x connection may be idle before it is automatically closed.
     */
    public function getHttp1Timeout(): int
    {
        return $this->http1Timeout;
    }

    /**
     * @param int $seconds Number of seconds an HTTP/1.x connection may be idle before it is automatically closed.
     *                     Default is 15.
     *
     * @return self
     *
     * @throws \Error If the number of seconds is less than 1.
     */
    public function withHttp1Timeout(int $seconds): self
    {
        if ($seconds < 1) {
            throw new \Error(
                "Keep alive timeout setting must be greater than or equal to one second"
            );
        }

        $new = clone $this;
        $new->http1Timeout = $seconds;

        return $new;
    }

    /**
     * @return int Number of seconds an HTTP/2 connection may be idle before it is automatically closed.
     */
    public function getHttp2Timeout(): int
    {
        return $this->http2Timeout;
    }

    /**
     * @param int $seconds Number of seconds an HTTP/2 connection may be idle before it is automatically closed.
     *                     Default is 60.
     *
     * @return self
     *
     * @throws \Error If the number of seconds is less than 1.
     */
    public function withHttp2Timeout(int $seconds): self
    {
        if ($seconds < 1) {
            throw new \Error(
                "HTTP/2 timeout setting must be greater than or equal to one second"
            );
        }

        $new = clone $this;
        $new->http2Timeout = $seconds;

        return $new;
    }

    /**
     * @return int Number of seconds a connection may take to setup TLS.
     */
    public function getTlsSetupTimeout(): int
    {
        return $this->tlsSetupTimeout;
    }

    /**
     * @param int $seconds Number of seconds connection may take to setup TLS.
     *                     Default is 5.
     *
     * @return self
     *
     * @throws \Error If the number of seconds is less than 1.
     */
    public function withTlsSetupTimeout(int $seconds): self
    {
        if ($seconds < 1) {
            throw new \Error(
                "TLS timeout setting must be greater than or equal to one second"
            );
        }

        $new = clone $this;
        $new->tlsSetupTimeout = $seconds;

        return $new;
    }

    /**
     * @return int Maximum request body size in bytes.
     */
    public function getBodySizeLimit(): int
    {
        return $this->bodySizeLimit;
    }

    /**
     * @param int $bytes Default maximum request body size in bytes. Individual requests may be increased by calling
     *                   `RequestBody::increaseSizeLimit($newLimit)`. Default is 131072 (128k).
     *
     * @return self
     *
     * @throws \Error If the number of bytes is less than 0.
     */
    public function withBodySizeLimit(int $bytes): self
    {
        if ($bytes < 0) {
            throw new \Error(
                "Max body size setting must be greater than or equal to zero"
            );
        }

        $new = clone $this;
        $new->bodySizeLimit = $bytes;

        return $new;
    }

    /**
     * @return int Maximum size of the request header section in bytes.
     */
    public function getHeaderSizeLimit(): int
    {
        return $this->headerSizeLimit;
    }

    /**
     * @param int $bytes Maximum size of the request header section in bytes. Default is 32768 (32k).
     *
     * @return self
     *
     * @throws \Error
     */
    public function withHeaderSizeLimit(int $bytes): self
    {
        if ($bytes < 1) {
            throw new \Error(
                "Max header size setting must be greater than zero"
            );
        }

        $new = clone $this;
        $new->headerSizeLimit = $bytes;

        return $new;
    }

    /**
     * @return int Maximum number of concurrent HTTP/2 streams.
     */
    public function getConcurrentStreamLimit(): int
    {
        return $this->concurrentStreamLimit;
    }

    /**
     * @param int $streams Maximum number of concurrent HTTP/2 streams. Default is 256.
     *
     * @return self
     *
     * @throws \Error If the stream count is less than 1.
     */
    public function withConcurrentStreamLimit(int $streams): self
    {
        if ($streams < 1) {
            throw new \Error(
                "Max number of concurrent streams setting must be greater than zero"
            );
        }

        $new = clone $this;
        $new->concurrentStreamLimit = $streams;

        return $new;
    }

    /**
     * @return int The maximum number of bytes to read from a client per read.
     */
    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }

    /**
     * @param int $bytes The maximum number of bytes to read from a client per read. Larger numbers are better for
     *                   performance but can increase memory usage. Default is 8192 (8k).
     *
     * @return self
     *
     * @throws \Error If the number of bytes is less than 1.
     */
    public function withChunkSize(int $bytes): self
    {
        if ($bytes < 1) {
            throw new \Error(
                "Chunk size setting must be greater than zero"
            );
        }

        $new = clone $this;
        $new->chunkSize = $bytes;

        return $new;
    }

    /**
     * @return int The minimum number of bytes to write to a client time for streamed responses.
     */
    public function getStreamThreshold(): int
    {
        return $this->streamThreshold;
    }

    /**
     * @param int $bytes TThe minimum number of bytes to write to a client time for streamed responses. Larger numbers
     *                   are better for performance but can increase memory usage. Default is 1024 (1k).
     *
     * @return self
     *
     * @throws \Error If the number of bytes is less than 1.
     */
    public function withStreamThreshold(int $bytes): self
    {
        if ($bytes < 1) {
            throw new \Error(
                "Stream threshold setting must be greater than zero"
            );
        }

        $new = clone $this;
        $new->streamThreshold = $bytes;

        return $new;
    }

    /**
     * @return string[] An array of allowed request methods.
     */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }

    /**
     * @param string[] $allowedMethods An array of allowed request methods. Default is GET, POST, PUT, PATCH, HEAD,
     *                                 OPTIONS, DELETE.
     *
     * @return self
     *
     * @throws \Error If the array contains non-strings, empty method names, or does not contain GET or HEAD.
     */
    public function withAllowedMethods(array $allowedMethods): self
    {
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
        $new->allowedMethods = $allowedMethods;

        return $new;
    }

    /**
     * @return bool `true` if HTTP/2 requests may be established through upgrade requests or prior knowledge.
     *     Disabled by default.
     */
    public function isHttp2UpgradeAllowed(): bool
    {
        return $this->allowHttp2Upgrade;
    }

    /**
     * Enables unencrypted upgrade or prior knowledge requests to HTTP/2.
     *
     * @return self
     */
    public function withHttp2Upgrade(): self
    {
        $new = clone $this;
        $new->allowHttp2Upgrade = true;

        return $new;
    }

    /**
     * Disables unencrypted upgrade or prior knowledge requests to HTTP/2.
     *
     * @return self
     */
    public function withoutHttp2Upgrade(): self
    {
        $new = clone $this;
        $new->allowHttp2Upgrade = false;

        return $new;
    }

    /**
     * @return bool `true` if HTTP/2 push promises will be sent by the server, `false` if pushes will only set a
     *              preload link header in the response.
     */
    public function isPushEnabled(): bool
    {
        return $this->pushEnabled;
    }

    /**
     * Enables HTTP/2 push promises.
     *
     * @return self
     */
    public function withPush(): self
    {
        $new = clone $this;
        $new->pushEnabled = true;

        return $new;
    }

    /**
     * Disables HTTP/2 push promises.
     *
     * @return self
     */
    public function withoutPush(): self
    {
        $new = clone $this;
        $new->pushEnabled = false;

        return $new;
    }

    /**
     * @return bool `true` if compression-by-default is enabled.
     */
    public function isCompressionEnabled(): bool
    {
        return $this->compression;
    }

    /**
     * Enables compression-by-default.
     *
     * @return self
     */
    public function withCompression(): self
    {
        $new = clone $this;
        $new->compression = true;

        return $new;
    }

    /**
     * Disables compression-by-default.
     *
     * @return self
     */
    public function withoutCompression(): self
    {
        $new = clone $this;
        $new->compression = false;

        return $new;
    }

    public function isRequestLogContextEnabled(): bool
    {
        return $this->requestLogContext;
    }

    /**
     * Enables passing the causing Request object to Logger.
     */
    public function withRequestLogContext(): self
    {
        $new = clone $this;
        $new->requestLogContext = true;

        return $new;
    }

    /**
     * Disables passing the causing Request object to Logger.
     */
    public function withoutRequestLogContext(): self
    {
        $new = clone $this;
        $new->requestLogContext = false;

        return $new;
    }
}

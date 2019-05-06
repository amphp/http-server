<?php

namespace Amp\Http\Server;

final class Options
{
    private $debug = false;
    private $connectionLimit = 10000;
    private $connectionsPerIpLimit = 30; // IPv4: /32, IPv6: /56 (per RFC 6177)
    private $connectionTimeout = 15; // seconds

    private $concurrentStreamLimit = 256;
    private $framesPerSecondLimit = 1024;
    private $minimumAverageFrameSize = 1024;
    private $allowedMethods = ["GET", "POST", "PUT", "PATCH", "HEAD", "OPTIONS", "DELETE"];

    private $bodySizeLimit = 131072;
    private $headerSizeLimit = 32768;
    private $chunkSize = 8192;
    private $streamThreshold = 8192;

    private $compression = true;
    private $allowHttp2Upgrade = false;

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
     * @return int Number of seconds a connection may be idle before it is automatically closed.
     */
    public function getConnectionTimeout(): int
    {
        return $this->connectionTimeout;
    }

    /**
     * @param int $seconds Number of seconds a connection may be idle before it is automatically closed. Default is 15.
     *
     * @return self
     *
     * @throws \Error If the number of seconds is less than 1.
     */
    public function withConnectionTimeout(int $seconds): self
    {
        if ($seconds < 1) {
            throw new \Error(
                "Keep alive timeout setting must be greater than or equal to one second"
            );
        }

        $new = clone $this;
        $new->connectionTimeout = $seconds;

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
     *     `RequestBody::increaseSizeLimit($newLimit)`. Default is 131072 (128k).
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
     * @param int $streams Maximum number of concurrent HTTP/2 streams. Default is 20.
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
     * @return int Minimum average frame size required if more than the maximum number of frames per second are
     *     received on an HTTP/2 connection.
     */
    public function getMinimumAverageFrameSize(): int
    {
        return $this->minimumAverageFrameSize;
    }

    /**
     * @param int $size Minimum average frame size required if more than the maximum number of frames per second are
     *     received on an HTTP/2 connection. Default is 1024 (1k).
     *
     * @return self
     *
     * @throws \Error If the size is less than 1.
     */
    public function withMinimumAverageFrameSize(int $size): self
    {
        if ($size < 1) {
            throw new \Error(
                "Minimum average frame size must be greater than zero"
            );
        }

        $new = clone $this;
        $new->minimumAverageFrameSize = $size;

        return $new;
    }

    /**
     * @return int Maximum number of HTTP/2 frames per second before the average length minimum is enforced.
     */
    public function getFramesPerSecondLimit(): int
    {
        return $this->framesPerSecondLimit;
    }

    /**
     * @param int $frames Maximum number of HTTP/2 frames per second before the average length minimum is enforced.
     *     Default is 60.
     *
     * @return self
     *
     * @throws \Error If the frame count is less than 1.
     */
    public function withFramesPerSecondLimit(int $frames): self
    {
        if ($frames < 1) {
            throw new \Error(
                "Max number of HTTP/2 frames per second setting must be greater than zero"
            );
        }

        $new = clone $this;
        $new->framesPerSecondLimit = $frames;

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
     *     performance but can increase memory usage. Default is 8192 (8k).
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
     *     are better for performance but can increase memory usage. Default is 1024 (1k).
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
     *     OPTIONS, DELETE.
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
}

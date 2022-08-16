<?php

namespace Amp\Http\Server\Middleware;

use Amp\ByteStream\IteratorStream;
use Amp\Coroutine;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Loop;
use Amp\Producer;
use Amp\Promise;
use Amp\TimeoutException;
use cash\LRUCache;

final class CompressionMiddleware implements Middleware
{
    const MAX_CACHE_SIZE = 1024;

    /** @link http://webmasters.stackexchange.com/questions/31750/what-is-recommended-minimum-object-size-for-deflate-performance-benefits */
    const DEFAULT_MINIMUM_LENGTH = 860;
    const DEFAULT_CHUNK_SIZE = 8192;
    const DEFAULT_BUFFER_TIMEOUT = 100;
    const DEFAULT_CONTENT_TYPE_REGEX = '#^(?:text/.*+|[^/]*+/xml|[^+]*\+xml|application/(?:json|(?:x-)?javascript))$#i';

    /** @var int Minimum body length before body is compressed. */
    private $minimumLength;

    /** @var string */
    private $contentRegex;

    /** @var int Minimum chunk size before being compressed. */
    private $chunkSize;

    /** @var LRUCache */
    private $contentTypeCache;

    /** @var int */
    private $bufferTimeout;

    public function __construct(
        int $minimumLength = self::DEFAULT_MINIMUM_LENGTH,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        string $contentRegex = self::DEFAULT_CONTENT_TYPE_REGEX,
        int $bufferTimeout = self::DEFAULT_BUFFER_TIMEOUT
    ) {
        if (!\extension_loaded('zlib')) {
            throw new \Error(__CLASS__ . ' requires ext-zlib');
        }

        if ($minimumLength < 1) {
            throw new \Error("The minimum length must be positive");
        }

        if ($chunkSize < 1) {
            throw new \Error("The chunk size must be positive");
        }

        if ($bufferTimeout < 1) {
            throw new \Error("The buffer timeout must be positive");
        }
        $this->contentTypeCache = new LRUCache(self::MAX_CACHE_SIZE);

        $this->minimumLength = $minimumLength;
        $this->chunkSize = $chunkSize;
        $this->contentRegex = $contentRegex;
        $this->bufferTimeout = $bufferTimeout;
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Promise
    {
        return new Coroutine($this->deflate($request, $requestHandler));
    }

    public function deflate(Request $request, RequestHandler $requestHandler): \Generator
    {
        /** @var \Amp\Http\Server\Response $response */
        $response = yield $requestHandler->handleRequest($request);

        $headers = $response->getHeaders();

        if (isset($headers["content-encoding"])) {
            return $response; // Another request handler or middleware has already encoded the response.
        }

        $contentLength = $headers["content-length"][0] ?? null;

        if ($contentLength !== null) {
            if ($contentLength < $this->minimumLength) {
                return $response; // Content-Length too small, no need to compress.
            }
        }

        // We can't deflate if we don't know the content-type
        if (empty($headers["content-type"])) {
            return $response;
        }

        $contentType = $headers["content-type"][0];

        $weight = 0;
        foreach ($request->getHeaderArray("accept-encoding") as $values) {
            foreach (\array_map("trim", \explode(",", $values)) as $value) {
                if (\preg_match('/^(gzip|deflate)(?:;q=(1(?:\.0{1,3})?|0(?:\.\d{1,3})?))?$/i', $value, $matches)) {
                    $qValue = (float) ($matches[2] ?? 1);
                    if ($qValue <= $weight) {
                        continue;
                    }

                    $weight = $qValue;
                    $encoding = \strtolower($matches[1]);
                }
            }
        }

        if (!isset($encoding)) {
            return $response;
        }

        $doDeflate = $this->contentTypeCache->get($contentType);

        if ($doDeflate === null) {
            $doDeflate = \preg_match($this->contentRegex, \trim(\strstr($contentType, ";", true) ?: $contentType));
            $this->contentTypeCache->put($contentType, $doDeflate);
        }

        if ($doDeflate === 0) {
            return $response;
        }

        $body = $response->getBody();
        $bodyBuffer = '';

        $promise = $body->read();

        if ($contentLength === null) {
            $expiration = Loop::now() + $this->bufferTimeout;

            try {
                do {
                    $bodyBuffer .= $chunk = yield Promise\timeout($promise, \max(1, $expiration - Loop::now()));

                    if (isset($bodyBuffer[$this->minimumLength])) {
                        break;
                    }

                    if ($chunk === null) {
                        // Body is not large enough to compress.
                        $response->setBody($bodyBuffer);
                        return $response;
                    }

                    $promise = $body->read();
                } while (true);
            } catch (TimeoutException $exception) {
                // Failed to buffer enough bytes within timeout, so continue to compressing body anyway.
            }
        }

        switch ($encoding) {
            case "deflate":
                $mode = \ZLIB_ENCODING_RAW;
                break;

            case "gzip":
                $mode = \ZLIB_ENCODING_GZIP;
                break;

            default:
                throw new \RuntimeException("Invalid encoding type");
        }

        if (($resource = \deflate_init($mode)) === false) {
            throw new \RuntimeException(
                "Failed initializing deflate context"
            );
        }

        // Once we decide to compress output we no longer know what the
        // final Content-Length will be. We need to update our headers
        // according to the HTTP protocol in use to reflect this.
        $response->removeHeader("content-length");
        if ($request->getProtocolVersion() === "1.0") { // Cannot chunk 1.0 responses.
            $response->setHeader("connection", "close");
        }
        $response->setHeader("content-encoding", $encoding);
        $response->addHeader("vary", "accept-encoding");

        $iterator = new Producer(function (callable $emit) use ($resource, $body, $promise, $bodyBuffer) {
            do {
                try {
                    $expiration = Loop::now() + $this->bufferTimeout;

                    while (!isset($bodyBuffer[$this->chunkSize - 1])) {
                        $bodyBuffer .= $chunk = yield $bodyBuffer === ''
                            ? $promise
                            : Promise\timeout($promise, \max(1, $expiration - Loop::now()));

                        if ($chunk === null) {
                            break 2;
                        }

                        $promise = $body->read();
                    }
                } catch (TimeoutException $exception) {
                    // Emit the bytes we do have.
                }

                if ($bodyBuffer !== '') {
                    if (false === $bodyBuffer = \deflate_add($resource, $bodyBuffer, \ZLIB_SYNC_FLUSH)) {
                        throw new \RuntimeException("Failed adding data to deflate context");
                    }

                    yield $emit($bodyBuffer);
                    $bodyBuffer = '';
                }
            } while (true);

            if (false === $bodyBuffer = \deflate_add($resource, $bodyBuffer, \ZLIB_FINISH)) {
                throw new \RuntimeException("Failed finishing deflate context");
            }

            $emit($bodyBuffer);
        });

        $response->setBody(new IteratorStream($iterator));

        return $response;
    }
}

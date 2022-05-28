<?php

namespace Amp\Http\Server\Middleware;

use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use cash\LRUCache;

final class CompressionMiddleware implements Middleware
{
    public const MAX_CACHE_SIZE = 1024;

    /** @link http://webmasters.stackexchange.com/questions/31750/what-is-recommended-minimum-object-size-for-deflate-performance-benefits */
    public const DEFAULT_MINIMUM_LENGTH = 860;
    public const DEFAULT_CHUNK_SIZE = 8192;
    public const DEFAULT_CONTENT_TYPE_REGEX = '#^(?:text/.*+|[^/]*+/xml|[^+]*\+xml|application/(?:json|(?:x-)?javascript))$#i';

    private readonly LRUCache $contentTypeCache;

    /**
     * @param positive-int $minimumLength Minimum body length before body is compressed.
     * @param positive-int $chunkSize Minimum chunk size before being compressed.
     * @param string $contentRegex
     */
    public function __construct(
        private readonly int $minimumLength = self::DEFAULT_MINIMUM_LENGTH,
        private readonly int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        private readonly string $contentRegex = self::DEFAULT_CONTENT_TYPE_REGEX
    ) {
        if (!\extension_loaded('zlib')) {
            throw new \Error(__CLASS__ . ' requires ext-zlib');
        }

        /** @psalm-suppress TypeDoesNotContainType */
        if ($minimumLength < 1) {
            throw new \Error("The minimum length must be positive");
        }

        /** @psalm-suppress TypeDoesNotContainType */
        if ($chunkSize < 1) {
            throw new \Error("The chunk size must be positive");
        }

        $this->contentTypeCache = new LRUCache(self::MAX_CACHE_SIZE);
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $response = $requestHandler->handleRequest($request);

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

        if ($contentLength === null) {
            do {
                $bodyBuffer .= $chunk = $body->read();

                if (isset($bodyBuffer[$this->minimumLength])) {
                    break;
                }

                if ($chunk === null) {
                    // Body is not large enough to compress.
                    $response->setBody($bodyBuffer);
                    return $response;
                }
            } while (true);
        }

        $mode = match ($encoding) {
            "deflate" => \ZLIB_ENCODING_RAW,
            "gzip" => \ZLIB_ENCODING_GZIP,
            default => throw new \RuntimeException("Invalid encoding type"),
        };

        if (($context = \deflate_init($mode)) === false) {
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

        /** @psalm-suppress InvalidArgument Psalm stubs are out of date, deflate_init returns a \DeflateContext */
        $response->setBody(
            new ReadableIterableStream(self::readBody($context, $body, $bodyBuffer, $this->chunkSize))
        );

        return $response;
    }

    /**
     * @psalm-suppress InvalidArgument
     */
    private static function readBody(
        \DeflateContext $context,
        ReadableStream $body,
        string $bodyBuffer,
        int $chunkSize,
    ): \Generator {
        do {
            if (isset($bodyBuffer[$chunkSize - 1])) {
                if (false === $bodyBuffer = \deflate_add($context, $bodyBuffer, \ZLIB_SYNC_FLUSH)) {
                    throw new \RuntimeException("Failed adding data to deflate context");
                }

                yield $bodyBuffer;
                $bodyBuffer = '';
            }

            $bodyBuffer .= $chunk = $body->read();
        } while ($chunk !== null);

        if (false === $bodyBuffer = \deflate_add($context, $bodyBuffer, \ZLIB_FINISH)) {
            throw new \RuntimeException("Failed adding data to deflate context");
        }

        yield $bodyBuffer;
    }
}

<?php declare(strict_types=1);

namespace Amp\Http\Server\Middleware;

use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\Cache\LocalCache;
use Amp\CancelledException;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\TimeoutCancellation;

final class CompressionMiddleware implements Middleware
{
    private static ?\Closure $errorHandler;

    public const MAX_CACHE_SIZE = 1024;

    /** @link http://webmasters.stackexchange.com/questions/31750/what-is-recommended-minimum-object-size-for-deflate-performance-benefits */
    public const DEFAULT_MINIMUM_LENGTH = 860;
    public const DEFAULT_BUFFER_TIMEOUT = 0.1;
    public const DEFAULT_CONTENT_TYPE_REGEX = '#^(?:text/.*+|[^/]*+/xml|[^+]*\+xml|application/(?:json|(?:x-)?javascript))$#i';

    /** @var LocalCache<bool> */
    private readonly LocalCache $contentTypeCache;

    /**
     * @param positive-int $minimumLength Minimum body length before body is compressed.
     * @param non-empty-string $contentRegex Content-Type regex.
     */
    public function __construct(
        private readonly int $minimumLength = self::DEFAULT_MINIMUM_LENGTH,
        private readonly string $contentRegex = self::DEFAULT_CONTENT_TYPE_REGEX,
        private readonly float $bufferTimeout = self::DEFAULT_BUFFER_TIMEOUT,
    ) {
        if (!\extension_loaded('zlib')) {
            throw new \Error(__CLASS__ . ' requires ext-zlib');
        }

        /** @psalm-suppress DocblockTypeContradiction */
        if ($minimumLength < 1) {
            throw new \Error("The minimum length must be positive");
        }

        if ($bufferTimeout <= 0) {
            throw new \Error("The buffer timeout must be positive");
        }

        $this->contentTypeCache = new LocalCache(self::MAX_CACHE_SIZE);
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
            $this->contentTypeCache->set($contentType, (bool) $doDeflate);
        }

        if (!$doDeflate) {
            return $response;
        }

        $body = $response->getBody();
        $bodyBuffer = '';

        if ($contentLength === null && !$this->shouldCompress($body, $bodyBuffer)) {
            $response->setBody($bodyBuffer);
            return $response;
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
            new ReadableIterableStream(self::readBody($context, $body, $bodyBuffer))
        );

        return $response;
    }

    private function shouldCompress(ReadableStream $body, string &$bodyBuffer): bool
    {
        try {
            $cancellation = new TimeoutCancellation($this->bufferTimeout);

            do {
                $bodyBuffer .= $chunk = $body->read($cancellation);

                if (isset($bodyBuffer[$this->minimumLength])) {
                    return true;
                }

                if ($chunk === null) {
                    return false;
                }
            } while (true);
        } catch (CancelledException) {
            // Not enough bytes buffered within timeout to determine body size, so use compression by default.
        }

        return true;
    }

    /**
     * @psalm-suppress InvalidArgument
     */
    private static function readBody(
        \DeflateContext $context,
        ReadableStream $body,
        string $chunk,
    ): \Generator {
        self::$errorHandler ??= static function (int $code, string $message): never {
            throw new \RuntimeException('Compression error: ' . $message, $code);
        };

        do {
            if ($chunk !== '') {
                \set_error_handler(self::$errorHandler);

                try {
                    $chunk = \deflate_add($context, $chunk, \ZLIB_SYNC_FLUSH);
                } finally {
                    \restore_error_handler();
                }

                yield $chunk;
            }

            $chunk = $body->read();
        } while ($chunk !== null);

        \set_error_handler(self::$errorHandler);

        try {
            $chunk = \deflate_add($context, '', \ZLIB_FINISH);
        } finally {
            \restore_error_handler();
        }

        yield $chunk;
    }
}

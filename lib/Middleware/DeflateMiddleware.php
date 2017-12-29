<?php

namespace Aerys\Middleware;

use Aerys\Middleware;
use Aerys\Request;
use Aerys\Responder;
use Aerys\Response;
use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\IteratorStream;
use Amp\Coroutine;
use Amp\Producer;
use Amp\Promise;

class DeflateMiddleware implements Middleware {
    const MAX_CACHE_SIZE = 1024;

    /**
     * @var int Minimum body length before body is compressed.
     * @link http://webmasters.stackexchange.com/questions/31750/what-is-recommended-minimum-object-size-for-deflate-performance-benefits
     */
    private $minimumLength = 860;

    /** @var string */
    private $contentRegex = '#^(?:text/.*+|[^/]*+/xml|[^+]*\+xml|application/(?:json|(?:x-)?javascript))$#i';

    /** @var int Minimum chunk size before being compressed. */
    private $chunkSize = 8192;

    /** @var int[] */
    private $contentTypeCache = [];

    public function process(Request $request, Responder $responder): Promise {
        return new Coroutine($this->do($request, $responder));
    }

    public function do(Request $request, Responder $responder): \Generator {
        /** @var \Aerys\Response $response */
        $response = yield $responder->respond($request);

        $headers = $response->getHeaders();
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

        // @TODO Perform a more sophisticated check for gzip acceptance.
        // This check isn't technically correct as the gzip parameter
        // could have a q-value of zero indicating "never accept gzip."
        do {
            foreach ($request->getHeaderArray("accept-encoding") as $value) {
                if (\preg_match('/gzip|deflate/i', $value, $matches)) {
                    $encoding = \strtolower($matches[0]);
                    break 2;
                }
            }
            return $response;
        } while (false);

        // Match and cache Content-Type
        if (!$doDeflate = $this->contentTypeCache[$headers["content-type"][0]] ?? null) {
            if ($doDeflate === 0) {
                return $response;
            }

            if (\count($this->contentTypeCache) === self::MAX_CACHE_SIZE) {
                unset($this->contentTypeCache[\key($this->contentTypeCache)]);
            }

            $contentType = $headers["content-type"][0];
            $doDeflate = \preg_match($this->contentRegex, \trim(\strstr($contentType, ";", true) ?: $contentType));
            $this->contentTypeCache[$contentType] = $doDeflate;

            if ($doDeflate === 0) {
                return $response;
            }
        }

        return yield from $this->deflate($request, $response, $encoding, $contentLength === null);
    }

    private function deflate(Request $request, Response $response, string $encoding, bool $examineBody): \Generator {
        $body = $response->getBody();
        $bodyBuffer = '';

        if ($examineBody) {
            do {
                $bodyBuffer .= $chunk = yield $body->read();

                if (isset($bodyBuffer[$this->minimumLength])) {
                    break;
                }

                if ($chunk === null) {
                    // Body is not large enough to compress.
                    $response->setHeader("content-length", \strlen($bodyBuffer));
                    $response->setBody(new InMemoryStream($bodyBuffer));
                    return $response;
                }
            } while (true);
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

        $iterator = new Producer(function (callable $emit) use ($resource, $body, $bodyBuffer) {
            do {
                if (isset($bodyBuffer[$this->chunkSize])) {
                    if (false === $data = \deflate_add($resource, $bodyBuffer, \ZLIB_SYNC_FLUSH)) {
                        throw new \RuntimeException("Failed adding data to deflate context");
                    }

                    $bodyBuffer = '';
                    yield $emit($data);
                }

                $bodyBuffer .= $chunk = yield $body->read();
            } while ($chunk !== null);

            if (false === $data = \deflate_add($resource, $bodyBuffer, \ZLIB_FINISH)) {
                throw new \RuntimeException("Failed adding data to deflate context");
            }

            $emit($data);
        });

        $response->setBody(new IteratorStream($iterator));

        return $response;
    }
}

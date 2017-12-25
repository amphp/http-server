<?php

namespace Aerys\Internal;

use Aerys\Middleware;
use Aerys\Options;
use Aerys\Request;
use Aerys\Response;
use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\IteratorStream;
use Amp\Producer;

class DeflateMiddleware implements Middleware {
    /** @var array */
    private $deflateContentTypes = [];
    
    public function process(Request $request, Response $response) {
        $accept = $request->getHeaderArray("accept-encoding");

        if (empty($accept)) {
            return $response;
        }

        $headers = $response->getHeaders();

        $minBodySize = $request->getOption("deflateMinimumLength");
        $contentLength = $headers["content-length"][0] ?? null;

        if ($contentLength !== null) {
            if ($contentLength < $minBodySize) {
                return $response; // Content-Length too small, no need to compress.
            }
        }

        // @TODO Perform a more sophisticated check for gzip acceptance.
        // This check isn't technically correct as the gzip parameter
        // could have a q-value of zero indicating "never accept gzip."
        do {
            foreach ($accept as $value) {
                if (\preg_match('/gzip|deflate/i', $value, $matches)) {
                    $encoding = \strtolower($matches[0]);
                    break 2;
                }
            }
            return $response;
        } while (false);

        // We can't deflate if we don't know the content-type
        if (empty($headers["content-type"])) {
            return $response;
        }

        // Match and cache Content-Type
        if (!$doDeflate = $this->deflateContentTypes[$headers["content-type"][0]] ?? null) {
            if ($doDeflate === 0) {
                return $response;
            }

            if (\count($this->deflateContentTypes) === Options::MAX_DEFLATE_ENABLE_CACHE_SIZE) {
                unset($this->deflateContentTypes[\key($this->deflateContentTypes)]);
            }

            $contentType = $headers["content-type"][0];
            $doDeflate = \preg_match($request->getOption("deflateContentTypes"), \trim(\strstr($contentType, ";", true) ?: $contentType));
            $this->deflateContentTypes[$contentType] = $doDeflate;

            if ($doDeflate === 0) {
                return $response;
            }
        }

        return $this->deflate($request, $response, $encoding, $contentLength === null);
    }

    private function deflate(Request $request, Response $response, string $encoding, bool $examineBody): \Generator {
        $minBodySize = $request->getOption("deflateMinimumLength");
        $body = $response->getBody();
        $bodyBuffer = '';

        if ($examineBody) {
            do {
                $bodyBuffer .= $chunk = yield $body->read();

                if (isset($bodyBuffer[$minBodySize])) {
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
        if ($request->getProtocolVersion() === "1.1") {
            $response->setHeader("transfer-encoding", "chunked");
        } else {
            $response->setHeader("connection", "close");
        }
        $response->setHeader("content-encoding", $encoding);
        $minFlushOffset = $request->getOption("deflateBufferSize");

        $iterator = new Producer(function (callable $emit) use ($resource, $body, $bodyBuffer, $minFlushOffset) {
            do {
                if (isset($bodyBuffer[$minFlushOffset])) {
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

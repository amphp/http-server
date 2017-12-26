<?php

namespace Aerys\Internal;

use Aerys\Options;
use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\IteratorStream;
use Amp\Producer;

class DeflateFilter implements Filter {
    /** @var array */
    private $deflateContentTypes = [];

    public function filter(Request $request, Response $response) { /* : ?\Generator */
        if (empty($request->headers["accept-encoding"])) {
            return;
        }

        $headers = $response->headers;

        $minBodySize = $request->client->options->deflateMinimumLength;
        $contentLength = $headers["content-length"][0] ?? null;

        if ($contentLength !== null) {
            if ($contentLength < $minBodySize) {
                return; // Content-Length too small, no need to compress.
            }
        }

        // @TODO Perform a more sophisticated check for gzip acceptance.
        // This check isn't technically correct as the gzip parameter
        // could have a q-value of zero indicating "never accept gzip."
        do {
            foreach ($request->headers["accept-encoding"] as $value) {
                if (\preg_match('/gzip|deflate/i', $value, $matches)) {
                    $encoding = \strtolower($matches[0]);
                    break 2;
                }
            }
            return;
        } while (false);

        // We can't deflate if we don't know the content-type
        if (empty($headers["content-type"])) {
            return;
        }

        // Match and cache Content-Type
        if (!$doDeflate = $this->deflateContentTypes[$headers["content-type"][0]] ?? null) {
            if ($doDeflate === 0) {
                return;
            }

            if (\count($this->deflateContentTypes) === Options::MAX_DEFLATE_ENABLE_CACHE_SIZE) {
                unset($this->deflateContentTypes[\key($this->deflateContentTypes)]);
            }

            $contentType = $headers["content-type"][0];
            $doDeflate = \preg_match($request->client->options->deflateContentTypes, \trim(\strstr($contentType, ";", true) ?: $contentType));
            $this->deflateContentTypes[$contentType] = $doDeflate;

            if ($doDeflate === 0) {
                return;
            }
        }

        return $this->deflate($request, $response, $encoding, $contentLength === null);
    }

    private function deflate(Request $request, Response $response, string $encoding, bool $examineBody): \Generator {
        $minBodySize = $request->client->options->deflateMinimumLength;
        $body = $response->body;
        $bodyBuffer = '';

        if ($examineBody) {
            do {
                $bodyBuffer .= $chunk = yield $body->read();

                if (isset($bodyBuffer[$minBodySize])) {
                    break;
                }

                if ($chunk === null) {
                    // Body is not large enough to compress.
                    $response->headers["content-length"] = [(string) \strlen($bodyBuffer)];
                    $response->body = new InMemoryStream($bodyBuffer);
                    return;
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
        unset($response->headers["content-length"]);
        if ($request->protocol === "1.1") {
            $response->headers["transfer-encoding"] = ["chunked"];
        } else {
            $response->headers["connection"] = ["close"];
        }
        $response->headers["content-encoding"] = [$encoding];
        $minFlushOffset = $request->client->options->deflateBufferSize;

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

        $response->body = new IteratorStream($iterator);
    }
}

<?php

namespace Aerys;

use Amp\{ Reactor, function makeGeneratorError };

/**
 * Create a router for use in a Host instance
 *
 * @param array $options Router options
 * @return \Aerys\Router Returns a Bootable Router instance
 */
function router(array $options = []) {
    $router = new Router;
    foreach ($options as $key => $value) {
        $router->setOption($key, $value);
    }

    return $router;
}

/**
 * Create a Websocket application for use in a Host instance
 *
 * @param \Aerys\Websocket|\Aerys\Bootable $app The websocket app to use
 * @param array $options Endpoint options
 * @return \Aerys\Bootable Returns a Bootable to manufacture an Aerys\Websocket\Endpoint
 */
function websocket($app, array $options = []) {
    return new class($app, $options) implements Bootable {
        private $app;
        private $options;
        public function __construct($app, array $options) {
            $this->app = $app;
            $this->options = $options;
        }
        public function boot(Reactor $reactor, Server $server, Logger $logger) {
            $app = ($this->app instanceof Bootable)
                ? $this->app->boot($reactor, $server, $logger)
                : $this->app;
            if (!$app instanceof Websocket) {
                $type = is_object($app) ? get_class($app) : gettype($app);
                throw new \DomainException(
                    "Cannot boot websocket handler; Aerys\\Websocket required, {$type} provided"
                );
            }
            $endpoint = new Websocket\Rfc6455Endpoint($reactor, $logger, $app);
            foreach ($this->options as $key => $value) {
                $endpoint->setOption($key, $value);
            }
            $this->app = null;
            $this->options = null;

            $server->attach($endpoint);

            return [$endpoint, "__invoke"];
        }
    };
}

/**
 * Create a static file root for use in a Host instance
 *
 * @param string $docroot The filesystem directory from which to serve documents
 * @param array $options Static file serving options
 * @return \Aerys\Bootable Returns a Bootable to manufacture an Aerys\Root\Root
 */
function root(string $docroot, array $options = []) {
    return new class($docroot, $options) implements Bootable {
        private $docroot;
        private $options;
        public function __construct(string $docroot, array $options) {
            $this->docroot = $docroot;
            $this->options = $options;
        }
        public function boot(Reactor $reactor, Server $server, Logger $logger) {
            $debug = $server->getOption("debug");
            $root = ($reactor instanceof \Amp\UvReactor)
                ? new Root\UvRoot($reactor, $this->docroot, $debug)
                : new Root\BlockingRoot($reactor, $this->docroot, $debug)
            ;
            $options = $this->options;
            $defaultMimeFile = __DIR__ ."/../etc/mime";
            if (!array_key_exists("mimeFile", $options) && file_exists($defaultMimeFile)) {
                $options["mimeFile"] = $defaultMimeFile;
            }
            foreach ($options as $key => $value) {
                $root->setOption($key, $value);
            }

            $server->attach($root);

            return [$root, "__invoke"];
        }
    };
}

/**
 * Parses cookies into an array
 *
 * @param string $cookies
 * @return array with name => value pairs
 */
function parseCookie(string $cookies): array {
    $arr = [];

    foreach (explode("; ", $cookies) as $cookie) {
        if (strpos($cookie, "=") !== false) { // do not trigger notices for malformed cookies...
            list($name, $val) = explode("=", $cookie, 2);
            $arr[$name] = $val;
        }
    }

    return $arr;
}

/**
 * Process requests and responses with filters
 *
 * Is this function's cyclomatic complexity off the charts? Yes. Is this an extremely hot
 * code path requiring maximum optimization? Yes. This is why it looks like the ninth
 * circle of npath hell ... #DealWithIt
 *
 * @param array $filters
 * @return \Generator
 */
function responseFilter(array $filters, ...$filterArgs): \Generator {
    try {
        $generators = [];
        foreach ($filters as $key => $filter) {
            $out = $filter(...$filterArgs);
            if ($out instanceof \Generator && $out->valid()) {
                $generators[$key] = $out;
            }
        }
        $filters = $generators;
        $isEnding = false;
        $isFlushing = false;
        $headers = yield;

        foreach ($filters as $key => $filter) {
            $yielded = $filter->send($headers);
            if (!isset($yielded)) {
                if (!$filter->valid()) {
                    $yielded = $filter->getReturn();
                    if (!isset($yielded)) {
                        unset($filters[$key]);
                        continue;
                    } elseif (is_array($yielded)) {
                        assert(__validateCodecHeaders($filter, $yielded));
                        $headers = $yielded;
                        unset($filters[$key]);
                        continue;
                    } else {
                        $type = is_object($yielded) ? get_class($yielded) : gettype($yielded);
                        throw new \DomainException(makeGeneratorError(
                            $filter,
                            "Codec error; header array required but {$type} returned"
                        ));
                    }
                }

                while (1) {
                    if ($isEnding) {
                        $toSend = null;
                    } elseif ($isFlushing) {
                        $toSend = false;
                    } else {
                        $toSend = yield;
                        if (!isset($toSend)) {
                            $isEnding = true;
                        } elseif ($toSend === false) {
                            $isFlushing = true;
                        }
                    }

                    $yielded = $filter->send($toSend);
                    if (!isset($yielded)) {
                        if ($isEnding || $isFlushing) {
                            if ($filter->valid()) {
                                $signal = isset($toSend) ? "FLUSH" : "END";
                                throw new \DomainException(makeGeneratorError(
                                    $filter,
                                    "Codec error; header array required from {$signal} signal"
                                ));
                            } else {
                                // this is always an error because the two-stage filter
                                // process means any filter receiving non-header data
                                // must participate in both stages
                                throw new \DomainException(makeGeneratorError(
                                    $filter,
                                    "Codec error; cannot detach without yielding/returning headers"
                                ));
                            }
                        }
                    } elseif (is_array($yielded)) {
                        assert(__validateCodecHeaders($filter, $yielded));
                        $headers = $yielded;
                        break;
                    } else {
                        $type = is_object($yielded) ? get_class($yielded) : gettype($yielded);
                        throw new \DomainException(makeGeneratorError(
                            $filter,
                            "Codec error; header array required but {$type} yielded"
                        ));
                    }
                }
            } elseif (is_array($yielded)) {
                assert(__validateCodecHeaders($filter, $yielded));
                $headers = $yielded;
            } else {
                $type = is_object($yielded) ? get_class($yielded) : gettype($yielded);
                throw new \DomainException(makeGeneratorError(
                    $filter,
                    "Codec error; header array required but {$type} yielded"
                ));
            }
        }

        $appendBuffer = null;
        $toSend = $headers;
        $isFlushing = false;

        do {
            $toSend = yield $toSend;
            if ($isFlushing) {
                $toSend = yield false;
            }
            $isFlushing = false;

            if ($isEnding) {
                $toSend = null;
            } elseif ($isFlushing) {
                $toSend = false;
            } else {
                if (!isset($toSend)) {
                    $isEnding = true;
                } elseif ($toSend === false) {
                    $isFlushing = true;
                }
            }

            foreach ($filters as $key => $filter) {
                while (1) {
                    $yielded = $filter->send($toSend);
                    if (!isset($yielded)) {
                        if (!$filter->valid()) {
                            unset($filters[$key]);
                            $yielded = $filter->getReturn();
                            if (!isset($yielded)) {
                                if (isset($appendBuffer)) {
                                    $toSend = $appendBuffer;
                                    $appendBuffer = null;
                                }
                                break;
                            } elseif (is_string($yielded)) {
                                if (isset($appendBuffer)) {
                                    $toSend = $appendBuffer . $yielded;
                                    $appendBuffer = null;
                                } else {
                                    $toSend = $yielded;
                                }
                                break;
                            } else {
                                $type = is_object($yielded) ? get_class($yielded) : gettype($yielded);
                                throw new \DomainException(makeGeneratorError(
                                    $filter,
                                    "Codec error; string entity data required but {$type} returned"
                                ));
                            }
                        } else {
                            if ($isEnding) {
                                if (isset($toSend)) {
                                    $toSend = null;
                                } else {
                                    break;
                                }
                            } elseif ($isFlushing) {
                                if ($toSend !== false) {
                                    $toSend = false;
                                } else {
                                    break;
                                }
                            } else {
                                $toSend = yield;
                                if (!isset($toSend)) {
                                    $isEnding = true;
                                } elseif ($toSend === false) {
                                    $isFlushing = true;
                                }
                            }
                        }
                    } elseif (is_string($yielded)) {
                        if (isset($appendBuffer)) {
                            $toSend = $appendBuffer . $yielded;
                            $appendBuffer = null;
                        } else {
                            $toSend = $yielded;
                        }
                        break;
                    } else {
                        $type = is_object($yielded) ? get_class($yielded) : gettype($yielded);
                        throw new \DomainException(makeGeneratorError(
                            $filter,
                            "Codec error; string entity data required but {$type} yielded"
                        ));
                    }
                }
            }

            if ($toSend === false) {
                $isFlushing = false;
            }
        } while (!$isEnding);

        return $toSend;
    } catch (ClientException $uncaught) {
        throw $uncaught;
    } catch (CodecException $uncaught) {
        // Userspace code isn't supposed to throw these; rethrow as
        // a different type to avoid breaking our error handling.
        throw new \Exception("", 0, $uncaught);
    } catch (\BaseException $uncaught) {
        throw new CodecException("Uncaught filter exception", $key, $uncaught);
    }
}

/**
 * A support function for responseCodec() generator debug-mode validation
 *
 * @param \Generator $generator
 * @param array $headers
 */
function __validateCodecHeaders(\Generator $generator, array $headers) {
    if (!isset($headers[":status"])) {
        throw new \DomainException(makeGeneratorError(
            $generator,
            "Missing :status key in yielded filter array"
        ));
    }
    if (!is_int($headers[":status"])) {
        throw new \DomainException(makeGeneratorError(
            $generator,
            "Non-integer :status key in yielded filter array"
        ));
    }
    if ($headers[":status"] < 100 || $headers[":status"] > 599) {
        throw new \DomainException(makeGeneratorError(
            $generator,
            ":status value must be in the range 100..599 in yielded filter array"
        ));
    }
    if (isset($headers[":reason"]) && !is_string($headers[":reason"])) {
        throw new \DomainException(makeGeneratorError(
            $generator,
            "Non-string :reason value in yielded filter array"
        ));
    }

    foreach ($headers as $headerField => $headerArray) {
        if (!is_string($headerField)) {
            throw new \DomainException(makeGeneratorError(
                $generator,
                "Invalid numeric header field index in yielded filter array"
            ));
        }
        if ($headerField[0] === ":") {
            continue;
        }
        if (!is_array($headerArray)) {
            throw new \DomainException(makeGeneratorError(
                $generator,
                "Invalid non-array header entry at key {$headerField} in yielded filter array"
            ));
        }
        foreach ($headerArray as $key => $headerValue) {
            if (!is_scalar($headerValue)) {
                throw new \DomainException(makeGeneratorError(
                    $generator,
                    "Invalid non-scalar header value at index {$key} of " .
                    "{$headerField} array in yielded filter array"
                ));
            }
        }
    }

    return true;
}

/**
 * Normalize outgoing headers according to the request protocol and server options
 *
 * @param \Aerys\InternalRequest $ireq
 * @param \Aerys\Options $options
 * @return \Generator
 */
function startResponseFilter(InternalRequest $ireq, Options $options): \Generator {
    $headers = yield;
    $status = $headers[":status"];

    if ($options->sendServerToken) {
        $headers["server"] = [SERVER_TOKEN];
    }

    $contentLength = $headers[":aerys-entity-length"];
    unset($headers[":aerys-entity-length"]);

    if ($contentLength === "@") {
        $hasContent = false;
        $shouldClose = ($ireq->protocol === "1.0");
        if (($status >= 200 && $status != 204 && $status != 304)) {
            $headers["content-length"] = ["0"];
        }
    } elseif ($contentLength !== "*") {
        $hasContent = true;
        $shouldClose = false;
        $headers["content-length"] = [$contentLength];
        unset($headers["transfer-encoding"]);
    } elseif ($ireq->protocol === "1.1") {
        $hasContent = true;
        $shouldClose = false;
        $headers["transfer-encoding"] = ["chunked"];
        unset($headers["content-length"]);
    } else {
        $hasContent = true;
        $shouldClose = true;
    }

    if ($hasContent) {
        $type = $headers["content-type"][0] ?? $options->defaultContentType;
        if (\stripos($type, "text/") === 0 && \stripos($type, "charset=") === false) {
            $type .= "; charset={$options->defaultTextCharset}";
        }
        $headers["content-type"] = [$type];
    }

    if ($shouldClose || $ireq->isServerStopping || $ireq->remaining === 0) {
        $headers["connection"] = ["close"];
    } else {
        $keepAlive = "timeout={$options->keepAliveTimeout}, max={$ireq->remaining}";
        $headers["keep-alive"] = [$keepAlive];
    }

    $headers["date"] = [$ireq->httpDate];

    return $headers;
}

/**
 * Apply negotiated gzip deflation to outgoing response bodies
 *
 * @param \Aerys\InternalRequest $ireq
 * @param \Aerys\Options $options
 * @return \Generator
 */
function deflateResponseFilter(InternalRequest $ireq, Options $options): \Generator {
    if (empty($ireq->headers["accept-encoding"])) {
        return;
    }

    // @TODO Perform a more sophisticated check for gzip acceptance.
    // This check isn't technically correct as the gzip parameter
    // could have a q-value of zero indicating "never accept gzip."
    foreach ($ireq->headers["accept-encoding"] as $value) {
        if (stripos($value, "gzip") !== false) {
            break;
        }
        return;
    }

    $headers = yield;

    // We can't deflate if we don't know the content-type
    if (empty($headers["content-type"])) {
        return;
    }

    // Require a text/* mime Content-Type
    // @TODO Allow option to configure which mime prefixes/types may be compressed
    if (stripos($headers["content-type"][0], "text/") !== 0) {
        return;
    }

    $minBodySize = $options->deflateMinimumLength;
    $contentLength = $headers["content-length"][0] ?? null;
    $bodyBuffer = "";

    if (!isset($contentLength)) {
        // Wait until we know there's enough stream data to compress before proceeding.
        // If we receive a FLUSH or an END signal before we have enough then we won't
        // use any compression.
        while (!isset($bodyBuffer[$minBodySize])) {
            $bodyBuffer .= ($tmp = yield);
            if ($tmp === false || $tmp === null) {
                $bodyBuffer .= yield $headers;
                return $bodyBuffer;
            }
        }
    } elseif (empty($contentLength) || $contentLength < $minBodySize) {
        // If the Content-Length is too small we can't compress it.
        return $headers;
    }

    // @TODO We have the ability to support DEFLATE and RAW encoding as well. Should we?
    $mode = \ZLIB_ENCODING_GZIP;
    if (($resource = \deflate_init($mode)) === false) {
        throw new \RuntimeException(
            "Failed initializing deflate context"
        );
    }

    // Once we decide to compress output we no longer know what the
    // final Content-Length will be. We need to update our headers
    // according to the HTTP protocol in use to reflect this.
    // @TODO This may require updating for HTTP/2.0
    unset($headers["content-length"]);
    if ($ireq->protocol === "1.1") {
        $headers["transfer-encoding"] = ["chunked"];
    } else {
        $headers["connection"] = ["close"];
    }
    $headers["content-encoding"] = ["gzip"];
    $minFlushOffset = $options->deflateBufferSize;
    $deflated = $headers;

    while (($uncompressed = yield $deflated) !== null) {
        $bodyBuffer .= $uncompressed;
        if ($uncompressed === false) {
            if ($bodyBuffer === "") {
                $deflated = null;
            } elseif (($deflated = \deflate_add($resource, $bodyBuffer, \ZLIB_SYNC_FLUSH)) === false) {
                throw new \RuntimeException(
                    "Failed adding data to deflate context"
                );
            } else {
                $bodyBuffer = "";
            }
        } elseif (!isset($bodyBuffer[$minFlushOffset])) {
            $deflated = null;
        } elseif (($deflated = \deflate_add($resource, $bodyBuffer)) === false) {
            throw new \RuntimeException(
                "Failed adding data to deflate context"
            );
        } else {
            $bodyBuffer = "";
        }
    }

    if (($deflated = \deflate_add($resource, $bodyBuffer, \ZLIB_FINISH)) === false) {
        throw new \RuntimeException(
            "Failed adding data to deflate context"
        );
    }

    return $deflated;
}

/**
 * Apply chunk encoding to response entity bodies
 *
 * @param \Aerys\InternalRequest $ireq
 * @param \Aerys\Options $options
 * @return \Generator
 */
function chunkedResponseFilter(InternalRequest $ireq, Options $options = null): \Generator {
    $headers = yield;

    if ($ireq->protocol !== "1.1") {
        return;
    }
    if (empty($headers["transfer-encoding"])) {
        return;
    }
    if (!in_array("chunked", $headers["transfer-encoding"])) {
        return;
    }

    $bodyBuffer = "";
    $bufferSize = $options->chunkBufferSize ?? 8192;
    $unchunked = yield $headers;

    do {
        $bodyBuffer .= $unchunked;
        if (isset($bodyBuffer[$bufferSize]) || ($unchunked === false && $bodyBuffer != "")) {
            $chunk = \dechex(\strlen($bodyBuffer)) . "\r\n{$bodyBuffer}\r\n";
            $bodyBuffer = "";
        } else {
            $chunk = null;
        }
    } while (($unchunked = yield $chunk) !== null);

    $chunk = ($bodyBuffer != "")
        ? (\dechex(\strlen($bodyBuffer)) . "\r\n{$bodyBuffer}\r\n0\r\n\r\n")
        : "0\r\n\r\n"
    ;

    return $chunk;
}

/**
 * Filter out entity body data from a response stream
 *
 * @param \Aerys\InternalRequest $ireq
 * @return \Generator
 */
function nullBodyResponseFilter(InternalRequest $ireq): \Generator {
    // Receive headers and immediately send them back.
    yield yield;
    // Yield null (need more data) for all subsequent body data
    while (yield !== null);
}

/**
 * Use a generic HTML response if the requisite header is assigned
 *
 * @param \Aerys\InternalRequest $ireq
 * @return \Generator
 */
function genericResponseFilter(InternalRequest $ireq, Options $options = null): \Generator {
    $headers = yield;
    if (empty($headers["aerys-generic-response"])) {
        return;
    }

    $body = makeGenericBody($headers[":status"], $options = [
        "reason"      => $headers[":reason"],
        "sub_heading" => "Requested: {$ireq->uri}",
        "server"      => $options->sendServerToken ?? false,
        "http_date"   => $ireq->httpDate,
    ]);
    $headers["content-length"] = [strlen($body)];
    unset(
        $headers["aerys-generic-response"],
        $headers["transfer-encoding"]
    );

    yield $headers;

    return $body;
}

/**
 * Create a generic HTML entity body
 *
 * @param int $status
 * @param array $options
 * @return string
 */
function makeGenericBody(int $status, array $options = []): string {
    $reason = $options["reason"] ?? (HTTP_REASON[$status] ?? "");
    $subhead = isset($options["sub_heading"]) ? "<h3>{$options["sub_heading"]}</h3>" : "";
    $server = empty($options["server_token"]) ? "" : (SERVER_TOKEN . " @ ");
    $date = $options["http_date"] ?? gmdate("D, d M Y H:i:s") . " GMT";
    $msg = isset($options["msg"]) ? "{$options["msg"]}\n" : "";

    return sprintf(
        "<html>\n<body>\n<h1>%d %s</h1>\n%s\n<hr/>\n<em>%s%s</em>\n<br/><br/>\n%s</body>\n</html>",
        $status,
        $reason,
        $subhead,
        $server,
        $date,
        $msg
    );
}

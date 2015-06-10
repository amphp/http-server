<?php

namespace Aerys;

/**
 * Create a router for use with Host instances
 *
 * @param array $options Router options
 * @return \Aerys\Router
 */
function router(array $options = []) {
    $router = new Router;
    foreach ($options as $key => $value) {
        $router->setOption($options);
    }
    return $router;
}

/**
 * Create a Websocket application action for attachment to a Host
 *
 * @param \Aerys\Websocket $app The websocket app to use
 * @param array $options Endpoint options
 * @return \Aerys\WebsocketEndpoint
 */
function websocket(Websocket $app, array $options = []) {
    $endpoint = new Rfc6455Endpoint($app);
    foreach ($options as $key => $value) {
        $endpoint->setOption($key, $value);
    }
    return $endpoint;
}

/**
 * Create a static file root for use with Host instances
 *
 * @param string $docroot The filesystem directory from which to serve documents
 * @param array $options Root options
 * @return \Aerys\Root\Root
 */
function root(string $docroot, array $options = []) {
    $reactor = \Amp\reactor();
    if ($reactor instanceof \Amp\NativeReactor) {
        $root = new Root\BlockingRoot($docroot, $reactor);
    } elseif ($reactor instanceof \Amp\UvReactor) {
        $root = new Root\UvRoot($docroot, $reactor);
    }

    $defaultMimeFile = __DIR__ ."/../etc/mime";
    if (!array_key_exists("mimeFile", $options) && file_exists($defaultMimeFile)) {
        $options["mimeFile"] = $defaultMimeFile;
    }
    foreach ($options as $key => $value) {
        $root->setOption($key, $value);
    }

    return $root;
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
 * A general purpose function for creating error messages from generator yields
 *
 * @param string $prefix
 * @param \Generator $generator
 * @return string
 */
function makeGeneratorError(string $prefix, \Generator $generator): string {
    if (!$generator->valid()) {
        return $prefix;
    }

    $reflGen = new \ReflectionGenerator($generator);
    $exeGen = $reflGen->getExecutingGenerator();
    if ($isSubgenerator = ($exeGen !== $generator)) {
        $reflGen = new \ReflectionGenerator($exeGen);
    }

    return sprintf(
        $prefix . " on line %s in %s",
        $reflGen->getExecutingLine(),
        $reflGen->getExecutingFile()
    );
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
                            "Codec error; header array required but {$type} returned",
                            $filter
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
                                    "Codec error; header array required from {$signal} signal",
                                    $filter
                                ));
                            } else {
                                // this is always an error because the two-stage filter
                                // process means any filter receiving non-header data
                                // must participate in both stages
                                throw new \DomainException(makeGeneratorError(
                                    "Codec error; cannot detach without yielding/returning headers",
                                    $filter
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
                            "Codec error; header array required but {$type} yielded",
                            $filter
                        ));
                    }
                }
            } elseif (is_array($yielded)) {
                assert(__validateCodecHeaders($filter, $yielded));
                $headers = $yielded;
            } else {
                $type = is_object($yielded) ? get_class($yielded) : gettype($yielded);
                throw new \DomainException(makeGeneratorError(
                    "Codec error; header array required but {$type} yielded",
                    $filter
                ));
            }
        }

        $toSend = yield $headers;

        $appendBuffer = null;

        do {
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
                                    "Codec error; string entity data required but {$type} returned",
                                    $filter
                                ));
                            }
                        } else {
                            if ($isEnding) {
                                $toSend = null;
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
                            "Codec error; string entity data required but {$type} yielded",
                            $filter
                        ));
                    }
                }
            }

            if ($isEnding && $toSend === null) {
                break;
            }
            if ($toSend === false) {
                $isFlushing = false;
            }
            $toSend = yield $toSend;
            if ($isFlushing) {
                $toSend = yield $toSend = false;
            }
            $isFlushing = false;
        } while (!$isEnding);
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
            "Missing :status key in yielded filter array",
            $generator
        ));
    }
    if (!is_int($headers[":status"])) {
        throw new \DomainException(makeGeneratorError(
            "Non-integer :status key in yielded filter array",
            $generator
        ));
    }
    if ($headers[":status"] < 100 || $headers[":status"] > 599) {
        throw new \DomainException(makeGeneratorError(
            ":status value must be in the range 100..599 in yielded filter array",
            $generator
        ));
    }
    if (isset($headers[":reason"]) && !is_string($headers[":reason"])) {
        throw new \DomainException(makeGeneratorError(
            "Non-string :reason value in yielded filter array",
            $generator
        ));
    }

    foreach ($headers as $headerField => $headerArray) {
        if (!is_string($headerField)) {
            throw new \DomainException(makeGeneratorError(
                "Invalid numeric header field index in yielded filter array",
                $generator
            ));
        }
        if ($headerField[0] === ":") {
            continue;
        }
        if (!is_array($headerArray)) {
            throw new \DomainException(makeGeneratorError(
                "Invalid non-array header entry at key {$headerField} in yielded filter array",
                $generator
            ));
        }
        foreach ($headerArray as $key => $headerValue) {
            if (!is_scalar($headerValue)) {
                throw new \DomainException(makeGeneratorError(
                    "Invalid non-scalar header value at index {$key} of " .
                    "{$headerField} array in yielded filter array",
                    $generator
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
    if (empty($options->deflateEnable)) {
        return;
    }
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

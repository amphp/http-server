<?php

namespace Aerys;

use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\makeGeneratorError;

/**
 * Create a router for use in a Host instance
 *
 * @param array $options Router options
 * @return \Aerys\Router Returns a Bootable Router instance
 */
function router(array $options = []): Router {
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
function websocket($app, array $options = []): Bootable {
    return new class($app, $options) implements Bootable {
        private $app;
        private $options;
        public function __construct($app, array $options) {
            $this->app = $app;
            $this->options = $options;
        }
        public function boot(Server $server, PsrLogger $logger) {
            $app = ($this->app instanceof Bootable)
                ? $this->app->boot($server, $logger)
                : $this->app;
            if (!$app instanceof Websocket) {
                $type = is_object($app) ? get_class($app) : gettype($app);
                throw new \InvalidArgumentException(
                    "Cannot boot websocket handler; Aerys\\Websocket required, {$type} provided"
                );
            }
            $endpoint = new Websocket\Rfc6455Endpoint($logger, $app);
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
 * @return \Aerys\Bootable Returns a Bootable to manufacture an Aerys\Root
 */
function root(string $docroot, array $options = []): Bootable {
    return new class($docroot, $options) implements Bootable {
        private $docroot;
        private $options;
        public function __construct(string $docroot, array $options) {
            $this->docroot = $docroot;
            $this->options = $options;
        }
        public function boot(Server $server, PsrLogger $logger) {
            $root = new Root($this->docroot);
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
 * Try parsing a the Request's body with either x-www-form-urlencoded or multipart/form-data
 *
 * @param Request $req
 * @return BodyParser (returns a ParsedBody instance when yielded)
 */
function parseBody(Request $req, $size = 0): BodyParser {
    return new BodyParser($req, [
        "input_vars" => $req->getOption("maxInputVars"),
        "field_len" => $req->getOption("maxFieldLen"),
        "size" => $size <= 0 ? $req->getOption("maxBodySize") : $size,
    ]);
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
 * Filter response data
 *
 * Is this function's cyclomatic complexity off the charts? Yes. Is this also an extremely
 * hot code path requiring maximum optimization? Yes. This is why it looks like the ninth
 * circle of npath hell ... #DealWithIt
 *
 * @param array $filters *ordered* filters array
 * @param \Aerys\InternalRequest $ireq
 * @return \Generator
 */
function responseFilter(array $filters, InternalRequest $ireq): \Generator {
    try {
        $generators = [];
        foreach ($filters as $key => $filter) {
            $out = $filter($ireq);
            if ($out instanceof \Generator && $out->valid()) {
                $generators[$key] = $out;
            }
        }
        $filters = $generators;

        $isEnding = false;
        $isFlushing = false;
        $hadHeaders = -1;
        $send = null;

        do {
            $toSend[] = $yielded = yield $send;
            if ($yielded === null) {
                $isEnding = true;
                $isFlushing = true;
            } elseif ($yielded === false) {
                $isFlushing = true;
            }

            foreach ($filters as $key => $filter) {
                $sendArray = $toSend;
                $toSend = null;

                do {
                    $send = array_shift($sendArray);
                    $yielded = $filter->send($send);
                    if ($yielded === null || $yielded === false) {
                        if ($filter->valid()) {
                            if ($key > $hadHeaders) {
                                if ($send === null) {
                                    throw new \DomainException(makeGeneratorError(
                                        $filter,
                                        "Filter error; header array required from END (null) signal"
                                    ));
                                } elseif ($send === false) {
                                    throw new \DomainException(makeGeneratorError(
                                        $filter,
                                        "Filter error; header array required from FLUSH (false) signal"
                                    ));
                                }
                            }
                        } else {
                            unset($filters[$key]);
                            $yielded = $filter->getReturn();

                            if ($key > $hadHeaders) {
                                if (\is_array($yielded)) {
                                    assert(__validateFilterHeaders($filter, $yielded) ?: 1);
                                    $toSend[] = $yielded;
                                } elseif ($send === null || $send === false) {
                                    $type = is_object($yielded) ? get_class($yielded) : gettype($yielded);
                                    throw new \DomainException(makeGeneratorError(
                                        $filter,
                                        "Filter error; header array required but {$type} returned"
                                    ));
                                } else {
                                    // this is always an error because the two-stage filter
                                    // process means any filter receiving non-header data
                                    // must participate in both stages
                                    throw new \DomainException(makeGeneratorError(
                                        $filter,
                                        "Filter error; cannot detach without yielding/returning headers"
                                    ));
                                }
                            } elseif (\is_string($yielded)) {
                                if ($toSend && \is_string(\end($toSend))) {
                                    $toSend[\key($toSend)] .= $yielded;
                                } else {
                                    $toSend[] = $yielded;
                                }
                            } elseif ($yielded !== null && $yielded !== false) {
                                $type = is_object($yielded) ? get_class($yielded) : gettype($yielded);
                                throw new \DomainException(makeGeneratorError(
                                    $filter,
                                    "Filter error; string entity data required but {$type} returned"
                                ));
                            }

                            $toSend = $toSend ? array_merge($toSend, $sendArray) : $sendArray;
                            break;
                        }
                    } elseif (\is_string($yielded) && $key <= $hadHeaders) {
                        if ($toSend && \is_string(\end($toSend))) {
                            $toSend[\key($toSend)] .= $yielded;
                        } else {
                            $toSend[] = $yielded;
                        }
                    } elseif (\is_array($yielded) && $key > $hadHeaders) {
                        assert(__validateFilterHeaders($filter, $yielded) ?: 1);
                        $toSend[] = $yielded;
                        $hadHeaders = $key;
                        if ($isEnding && empty($sendArray)) {
                            $sendArray = [null];
                        }
                    } else {
                        $type = is_object($yielded) ? get_class($yielded) : gettype($yielded);
                        throw new \DomainException(makeGeneratorError(
                            $filter,
                            "Filter error; " . ($key > $hadHeaders ? "header array" : "string entity data") . " required but {$type} yielded"
                        ));
                    }
                } while ($sendArray);

                if ($isFlushing) {
                    $toSend[] = $isEnding ? null : false;
                }
                if ($toSend === null) {
                    break;
                }
            }

            $isFlushing = false;

            if ($toSend) {
                if (isset($toSend[1]) && $toSend[1] != "") {
                    $sendArray = $toSend;
                    $toSend = [];
                    if (\is_array($sendArray[0])) {
                        $send = array_shift($sendArray);

                        $toSend = [$yielded = yield $send];
                        if ($yielded === null) {
                            $isEnding = true;
                            $isFlushing = true;
                        } elseif ($yielded === false) {
                            $isFlushing = true;
                        }
                    }

                    $send = implode($sendArray);
                } else {
                    $send = $toSend[0];
                    $toSend = [];
                }
                if ($send === "") {
                    $send = null;
                }
            } else {
                $send = null;
                $toSend = [];
            }
        } while (!$isEnding);

        return $send;
    } catch (ClientException $uncaught) {
        throw $uncaught;
    } catch (\Throwable $uncaught) {
        $ireq->filterErrorFlag = true;
        $ireq->badFilterKeys[] = $key;
        throw new FilterException("Filter error", 0, $uncaught);
    }
}

function __validateFilterHeaders(\Generator $generator, array $headers) {
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
 * Manages a filter and pipes its yielded values to the InternalRequest->responseWriter
 * @param $filter \Generator a filter manager (like generated by responseFilter)
 */
function responseCodec(\Generator $filter, InternalRequest $ireq): \Generator {
    while (($yield = yield) !== null) {
        $cur = $filter->send($yield);

        if ($yield === false) {
            if ($cur !== null) {
                $ireq->responseWriter->send($cur);
                if (\is_array($cur)) { // in case of headers, to flush a maybe started body too, we need to send false twice
                    $cur = $filter->send(false);
                    if ($cur !== null) {
                        $ireq->responseWriter->send($cur);
                    }
                }
            }
            $ireq->responseWriter->send(false);
        } elseif ($cur !== null) {
            $ireq->responseWriter->send($cur);
        }
    }

    $cur = $filter->send(null);
    if (\is_array($cur)) {
        $ireq->responseWriter->send($cur);
        $filter->send(null);
    }
    \assert($filter->valid() === false);

    $cur = $filter->getReturn();
    if ($cur !== null) {
        $ireq->responseWriter->send($cur);
    }
    $ireq->responseWriter->send(null);
}


/**
 * Apply negotiated gzip deflation to outgoing response bodies
 *
 * @param \Aerys\InternalRequest $ireq
 * @return \Generator
 */
function deflateResponseFilter(InternalRequest $ireq): \Generator {
    if (empty($ireq->headers["accept-encoding"])) {
        return;
    }

    // @TODO Perform a more sophisticated check for gzip acceptance.
    // This check isn't technically correct as the gzip parameter
    // could have a q-value of zero indicating "never accept gzip."
    do {
        foreach ($ireq->headers["accept-encoding"] as $value) {
            if (stripos($value, "gzip") !== false) {
                break 2;
            }
        }
        return;
    } while (0);

    $headers = yield;

    // We can't deflate if we don't know the content-type
    if (empty($headers["content-type"])) {
        return $headers;
    }

    $options = $ireq->client->options;

    // Match and cache Content-Type
    if (!$doDeflate = $options->_dynamicCache->deflateContentTypes[$headers["content-type"][0]] ?? null) {
        if ($doDeflate === 0) {
            return $headers;
        }

        if (count($options->_dynamicCache->deflateContentTypes) == Options::MAX_DEFLATE_ENABLE_CACHE_SIZE) {
            unset($options->_dynamicCache->deflateContentTypes[key($options->_dynamicCache->deflateContentTypes)]);
        }
        
        $contentType = $headers["content-type"][0];
        $doDeflate = preg_match($options->deflateContentTypes, trim(strstr($contentType, ";", true) ?: $contentType));
        $options->_dynamicCache->deflateContentTypes[$contentType] = $doDeflate;

        if ($doDeflate === 0) {
            return $headers;
        }
    }

    $minBodySize = $options->deflateMinimumLength;
    $contentLength = $headers["content-length"][0] ?? null;
    $bodyBuffer = "";

    if (!isset($contentLength)) {
        // Wait until we know there's enough stream data to compress before proceeding.
        // If we receive a FLUSH or an END signal before we have enough then we won't
        // use any compression.
        do {
            $bodyBuffer .= ($tmp = yield);
            if ($tmp === false || $tmp === null) {
                $bodyBuffer .= yield $headers;
                return $bodyBuffer;
            }
        } while (!isset($bodyBuffer[$minBodySize]));
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
function genericResponseFilter(InternalRequest $ireq): \Generator {
    $headers = yield;
    if (empty($headers["aerys-generic-response"])) {
        return $headers;
    }

    $body = makeGenericBody($headers[":status"], $options = [
        "reason"      => $headers[":reason"],
        "sub_heading" => "Requested: {$ireq->uri}",
        "server"      => $ireq->client->options->sendServerToken ?? false,
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

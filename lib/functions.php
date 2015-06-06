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
 * Does the specified header field exist in the multi-line header string?
 *
 * @param string $headers
 * @param string $field
 * @return bool
 */
function hasHeader($headers, $field) {
    $headers = "\r\n" . trim($headers);
    $field = "\r\n" . rtrim($field, " :") . ':';

    return (stripos($headers, $field) === false) ? false : true;
}

/**
 * Retrieve the value from the first occurence of the specified header field or NULL if nonexistent.
 *
 * @param string $headers
 * @param string $field
 * @return null|string
 */
function getHeader($headers, $field) {
    $field = rtrim($field, " :") . ':';
    $tok = strtok("\r\n" . $headers, "\r\n");
    while ($tok !== FALSE) {
        if (stripos($tok, $field) === 0) {
            return trim(substr($tok, strlen($field)));
        }
        $tok = strtok("\r\n");
    }

    return null;
}

/**
 * Retrieve all values for the specified header field (or an empty array if none are found)
 *
 * @param string $headers
 * @param string $field
 * @return array
 */
function getHeaderArray($headers, $field) {
    $field = rtrim($field, " :") . ':';
    $tok = strtok("\r\n" . $headers, "\r\n");
    $values = [];
    while ($tok !== FALSE) {
        if (stripos($tok, $field) === 0) {
            $values[] = trim(substr($tok, strlen($field)));
        }
        $tok = strtok("\r\n");
    }

    return $values;
}

/**
 * Replace any occurence of the $field header with the new $value
 *
 * @param string $headers
 * @param string $field
 * @param string $value
 * @return string
 */
function setHeader($headers, $field, $value) {
    $headers = removeHeader($headers, $field);
    $headers .= "\r\n{$field}: {$value}";

    return $headers;
}

/**
 * Append the specified header field value
 *
 * @param string $headers
 * @param string $field
 * @param string $value
 * @return string
 */
function addHeader($headers, $field, $value) {
    return "{$headers}\r\n{$field}: {$value}";
}

/**
 * Append the specified header line
 *
 * @param string $headers
 * @param string $line
 * @return string
 */
function addHeaderLine($headers, $line) {
    return "{$headers}\r\n{$line}";
}

/**
 * Remove all occurrences of the specified header $field
 *
 * @param string $headers
 * @param string $field
 * @return string
 */
function removeHeader($headers, $field) {
    $headers = trim($headers);
    if (empty($headers)) {
        return $headers;
    }

    if (stripos("\r\n{$headers}", "\r\n{$field}:") === false) {
        return $headers;
    }

    $newHeaders = [];
    $fieldKey = rtrim($field, " :") . ':';
    foreach (explode("\r\n", $headers) as $line) {
        if (stripos($line, $fieldKey) !== 0) {
            $newHeaders[] = $line;
        }
    }

    return $newHeaders ? implode("\r\n", $newHeaders) : '';
}

/**
 * Does the header $field exist AND contain the specified $value?
 *
 * @param string $headers
 * @param string $field
 * @param string $value
 * @return bool
 * @TODO Make this work when multiple headers of the same field are present in $headers
 */
function headerMatches(string $headers, string $field, string $value) {
    if (empty($headers)) {
        return false;
    }

    // Normalize values for searching
    $headers = "\r\n{$headers}";
    $field = "\r\n" . trim($field, "\r\n\t\x20:") . ":";

    // If the header isn't found at all we're finished
    if (($lineStartPos = \stripos($headers, $field)) === false) {
        return false;
    }

    $valueLineOffset = $lineStartPos + \strlen($field);
    $valueLine = (($lineEndPos = \stripos($headers, "\r\n", $lineStartPos + 4)) === false)
        ? \substr($headers, $valueLineOffset)
        : \substr($headers, $valueLineOffset, $lineEndPos - $valueLineOffset);

    return (\stripos($valueLine, $value) !== false);
}

/**
 * Is the specified reason phrase valid according to RFC7230?
 *
 * @TODO Validate reason phrase against RFC7230 ABNF
 * @param string $phrase An HTTP reason phrase
 * @return bool
 * @link https://tools.ietf.org/html/rfc7230#section-3.1.2
 */
function isValidReasonPhrase(string $phrase): bool {
    // reason-phrase  = *( HTAB / SP / VCHAR / obs-text )
    return true;
}

/**
 * Is the specified header field valid according to RFC7230?
 *
 * @TODO Validate field name against RFC7230 ABNF
 * @param string $field An HTTP header field name
 * @return bool
 * @link https://tools.ietf.org/html/rfc7230#section-3.2
 */
function isValidHeaderField(string $field): bool {
    // field-name     = token
    return true;
}

/**
 * Is the specified header value valid according to RFC7230?
 *
 * @TODO Validate field name against RFC7230 ABNF
 * @param string $value An HTTP header field value
 * @return bool
 * @link https://tools.ietf.org/html/rfc7230#section-3.2
 */
function isValidHeaderValue(string $value): bool {
    // field-value    = *( field-content / obs-fold )
    // field-content  = field-vchar [ 1*( SP / HTAB ) field-vchar ]
    // field-vchar    = VCHAR / obs-text
    //
    // obs-fold       = CRLF 1*( SP / HTAB )
    //                ; obsolete line folding
    //                ; see Section 3.2.4
    return true;
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
 * Encode standardized response output into a byte-stream format
 *
 * Is this function's cyclomatic complexity off the charts? Yes. Is this an extremely hot
 * code path requiring maximum optimization? Yes. This is why it looks like the seventh
 * circle of npath hell ... #DealWithIt
 *
 * @param \Aerys\InternalRequest $ireq
 * @param \Generator $writer
 * @param array $filters
 * @return \Generator
 */
function responseCodec(InternalRequest $ireq, \Generator $writer, array $filters): \Generator {
    try {
        $generators = [];
        foreach ($filters as $key => $filter) {
            $out = $filter($ireq);
            if ($out instanceof \Generator) {
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

        $writer->send($headers);

        $appendBuffer = null;

        do {
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
                            break;
                        } elseif ($isEnding) {
                            $appendBuffer = $yielded;
                            $toSend = null;
                        } else {
                            $toSend = $yielded;
                            break;
                        }
                    } else {
                        $type = is_object($yielded) ? get_class($yielded) : gettype($yielded);
                        throw new \DomainException(makeGeneratorError(
                            "Codec error; string entity data required but {$type} yielded",
                            $filter
                        ));
                    }
                }
            }

            $writer->send($toSend);
            if ($isFlushing && $toSend !== false) {
                $writer->send($toSend = false);
            }
            $isFlushing = false;

        } while (!$isEnding);

        if (isset($toSend)) {
            $writer->send(null);
        }
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
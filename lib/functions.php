<?php

namespace Aerys;

use Amp\InvalidYieldError;
use Psr\Log\LoggerInterface as PsrLogger;

/**
 * Create a router for use in a Host instance.
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
 * Create a Websocket application for use in a Host instance.
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
        public function boot(Server $server, PsrLogger $logger): callable {
            $app = ($this->app instanceof Bootable)
                ? $this->app->boot($server, $logger)
                : $this->app;
            if (!$app instanceof Websocket) {
                $type = is_object($app) ? get_class($app) : gettype($app);
                throw new \Error(
                    "Cannot boot websocket handler; Aerys\\Websocket required, {$type} provided"
                );
            }
            $gateway = new Websocket\Rfc6455Gateway($logger, $app);
            foreach ($this->options as $key => $value) {
                $gateway->setOption($key, $value);
            }
            $this->app = null;
            $this->options = null;

            $server->attach($gateway);

            return $gateway;
        }
    };
}

/**
 * Create a static file root for use in a Host instance.
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
        public function boot(Server $server, PsrLogger $logger): callable {
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

            return $root;
        }
    };
}

/**
 * Create a redirect handler callable for use in a Host instance.
 *
 * @param string $absoluteUri Absolute URI prefix to redirect to
 * @param int $redirectCode HTTP status code to set
 * @return callable Responder callable
 */
function redirect(string $absoluteUri, int $redirectCode = 307): callable {
    if (!$url = @parse_url($absoluteUri)) {
        throw new \Error("Invalid redirect URI");
    }
    if (empty($url["scheme"]) || ($url["scheme"] !== "http" && $url["scheme"] !== "https")) {
        throw new \Error("Invalid redirect URI; \"http\" or \"https\" scheme required");
    }
    if (isset($url["query"]) || isset($url["fragment"])) {
        throw new \Error("Invalid redirect URI; Host redirect must not contain a query or fragment component");
    }

    $absoluteUri = rtrim($absoluteUri, "/");

    if ($redirectCode < 300 || $redirectCode > 399) {
        throw new \Error("Invalid redirect code; code in the range 300..399 required");
    }

    return function (Request $req, Response $res) use ($absoluteUri, $redirectCode) {
        $res->setStatus($redirectCode);
        $res->setHeader("location", $absoluteUri . $req->getUri());
        $res->end();
    };
}

/**
 * Try parsing a the Request's body with either x-www-form-urlencoded or multipart/form-data.
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
 * Parses cookies into an array.
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
 * Filter response data.
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
                                    throw new InvalidYieldError(
                                        $filter,
                                        "Filter error; header array required from END (null) signal"
                                    );
                                } elseif ($send === false) {
                                    throw new InvalidYieldError(
                                        $filter,
                                        "Filter error; header array required from FLUSH (false) signal"
                                    );
                                }
                            }
                        } else {
                            unset($filters[$key]);
                            $yielded = $filter->getReturn();

                            if ($key > $hadHeaders) {
                                if (\is_array($yielded)) {
                                    assert(Internal\validateFilterHeaders($filter, $yielded) ?: 1);
                                    $toSend[] = $yielded;
                                    if ($sendArray) {
                                        $toSend = array_merge($toSend, $sendArray);
                                    }
                                } elseif ($send === null || $send === false) {
                                    $type = is_object($yielded) ? get_class($yielded) : gettype($yielded);
                                    throw new InvalidYieldError(
                                        $filter,
                                        "Filter error; header array required but {$type} returned"
                                    );
                                } else {
                                    // this is always an error because the two-stage filter
                                    // process means any filter receiving non-header data
                                    // must participate in both stages
                                    throw new InvalidYieldError(
                                        $filter,
                                        "Filter error; cannot detach without yielding/returning headers"
                                    );
                                }
                            } elseif (\is_string($yielded)) {
                                if ($toSend && \is_string(\end($toSend))) {
                                    $toSend[\key($toSend)] .= $yielded;
                                } else {
                                    $toSend[] = $yielded;
                                }
                            } elseif ($yielded !== null && $yielded !== false) {
                                $type = is_object($yielded) ? get_class($yielded) : gettype($yielded);
                                throw new InvalidYieldError(
                                    $filter,
                                    "Filter error; string entity data required but {$type} returned"
                                );
                            }

                            \assert($key > $hadHeaders || $sendArray === [] || $sendArray === [null]);
                            break;
                        }
                    } elseif (\is_string($yielded) && $key <= $hadHeaders) {
                        if ($toSend && \is_string(\end($toSend))) {
                            $toSend[\key($toSend)] .= $yielded;
                        } else {
                            $toSend[] = $yielded;
                        }
                    } elseif (\is_array($yielded) && $key > $hadHeaders) {
                        assert(Internal\validateFilterHeaders($filter, $yielded) ?: 1);
                        $toSend[] = $yielded;
                        $hadHeaders = $key;
                        if ($isEnding && !$sendArray) {
                            $sendArray = [null];
                        }
                    } else {
                        $type = is_object($yielded) ? get_class($yielded) : gettype($yielded);
                        throw new InvalidYieldError(
                            $filter,
                            "Filter error; " . ($key > $hadHeaders ? "header array" : "string entity data") . " required but {$type} yielded"
                        );
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

/**
 * Manages a filter and pipes its yielded values to the responseWriter.
 * @param $filter \Generator a filter manager (like generated by responseFilter)
 */
function responseCodec(\Generator $filter, \Generator $responseWriter): \Generator {
    while (($yield = yield) !== null) {
        $cur = $filter->send($yield);

        if ($cur !== null) {
            $responseWriter->send($cur);
        }
    }

    $cur = $filter->send(null);
    if (\is_array($cur)) {
        $responseWriter->send($cur);
        $filter->send(null);
    }
    \assert($filter->valid() === false);

    $cur = $filter->getReturn();
    if ($cur !== null) {
        $responseWriter->send($cur);
    }
    $responseWriter->send(null);
}

/**
 * Create a generic HTML entity body.
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

/**
 * Initializes the server directly from a given set of Hosts.
 *
 * @param PsrLogger $logger
 * @param Host[] $hosts
 * @param array $options Aerys options array
 * @return Server
 */
function initServer(PsrLogger $logger, array $hosts, array $options = []): Server {
    foreach ($hosts as $host) {
        if (!$host instanceof Host) {
            throw new \TypeError(
                "Expected an array of Hosts as second parameter, but array also contains ".(is_object($host) ? "instance of " .get_class($host) : gettype($host))
            );
        }
    }

    if (!array_key_exists("debug", $options)) {
        $options["debug"] = false;
    }

    $options = Internal\generateOptionsObjFromArray($options);
    $vhosts = new VhostContainer(new Http1Driver);
    $ticker = new Ticker($logger);
    $server = new Server($options, $vhosts, $logger, $ticker);

    $bootLoader = static function (Bootable $bootable) use ($server, $logger) {
        $booted = $bootable->boot($server, $logger);
        if ($booted !== null && !$booted instanceof Middleware && !is_callable($booted)) {
            throw new \Error("Any return value of " . get_class($bootable) . '::boot() must return an instance of Aerys\Middleware and/or be callable, got ' . gettype($booted) . ".");
        }
        return $booted ?? $bootable;
    };
    foreach ($hosts ?: [new Host] as $host) {
        $vhost = Internal\buildVhost($host, $bootLoader);
        $vhosts->use($vhost);
    }

    return $server;
}

/**
 * Gives the absolute path of a config file.
 *
 * @param string $configFile path to config file used by Aerys instance
 * @return string
 */
function selectConfigFile(string $configFile): string {
    if ($configFile == "") {
        throw new \Error(
            "No config file found, specify one via the -c switch on command line"
        );
    }

    $path = realpath(is_dir($configFile) ? rtrim($configFile, "/") . "/config.php" : $configFile);

    if ($path === false) {
        throw new \Error("No config file found at " . $configFile);
    }

    return $path;
}

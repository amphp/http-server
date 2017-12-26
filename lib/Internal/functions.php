<?php

namespace Aerys\Internal;

use Aerys\Bootable;
use Aerys\Console;
use Aerys\Host;
use Aerys\Middleware;
use Aerys\Monitor;
use Aerys\Options;
use Aerys\Request;
use Aerys\Response;
use Aerys\Server;
use Aerys\ServerObserver;
use Aerys\Vhost;
use Amp\InvalidYieldError;
use Amp\Promise;
use Amp\Success;
use Psr\Log\LoggerInterface as PsrLogger;
use const Aerys\HTTP_STATUS;
use function Aerys\initServer;
use function Aerys\makeGenericBody;
use function Aerys\selectConfigFile;
use function Amp\call;
use function Amp\coroutine;

function validateFilterHeaders(\Generator $generator, array $headers): bool {
    if (!isset($headers[":status"])) {
        throw new InvalidYieldError(
            $generator,
            "Missing :status key in yielded filter array"
        );
    }
    if (!is_int($headers[":status"])) {
        throw new InvalidYieldError(
            $generator,
            "Non-integer :status key in yielded filter array"
        );
    }
    if ($headers[":status"] < 100 || $headers[":status"] > 599) {
        throw new InvalidYieldError(
            $generator,
            ":status value must be in the range 100..599 in yielded filter array"
        );
    }
    if (isset($headers[":reason"]) && !is_string($headers[":reason"])) {
        throw new InvalidYieldError(
            $generator,
            "Non-string :reason value in yielded filter array"
        );
    }

    foreach ($headers as $headerField => $headerArray) {
        if (!is_string($headerField)) {
            throw new InvalidYieldError(
                $generator,
                "Invalid numeric header field index in yielded filter array"
            );
        }
        if ($headerField[0] === ":") {
            continue;
        }
        if (!is_array($headerArray)) {
            throw new InvalidYieldError(
                $generator,
                "Invalid non-array header entry at key {$headerField} in yielded filter array"
            );
        }
        foreach ($headerArray as $key => $headerValue) {
            if (!is_scalar($headerValue)) {
                throw new InvalidYieldError(
                    $generator,
                    "Invalid non-scalar header value at index {$key} of " .
                    "{$headerField} array in yielded filter array"
                );
            }
        }
    }

    return true;
}

/**
 * Bootstrap a server from command line options.
 *
 * @param PsrLogger $logger
 * @param Console $console
 * @return \Generator
 */
function bootServer(PsrLogger $logger, Console $console): \Generator {
    $configFile = selectConfigFile((string) $console->getArg("config"));

    // may return Promise or Generator for async I/O inside config file
    $hosts = yield call(function () use (&$logger, $console, $configFile) {
        return include $configFile;
    });

    $logger->info("Using config file found at $configFile");

    if (!\is_array($hosts)) {
        $hosts = [$hosts];
    }

    if (empty($hosts)) {
        throw new \Error(
            "Config file at $configFile did not return any hosts"
        );
    }

    if (!defined("AERYS_OPTIONS")) {
        $options = [];
    } elseif (is_array(AERYS_OPTIONS)) {
        $options = AERYS_OPTIONS;
    } else {
        throw new \Error(
            "Invalid AERYS_OPTIONS constant: expected array, got " . gettype(AERYS_OPTIONS)
        );
    }
    if (array_key_exists("debug", $options)) {
        throw new \Error(
            'AERYS_OPTIONS constant contains "debug" key; "debug" option is read-only and only settable to true via the -d command line option'
        );
    }

    $options["debug"] = $console->isArgDefined("debug");
    if ($console->isArgDefined("user")) {
        $options["user"] = $console->getArg("user");
    }
    $options["configPath"] = $configFile;

    return initServer($logger, $hosts, $options);
}

function generateOptionsObjFromArray(array $optionsArray): Options {
    try {
        $optionsObj = new Options;
        foreach ($optionsArray as $key => $value) {
            $optionsObj->{$key} = $value;
        }
        try {
            if (@assert(false)) {
                return generatePublicOptionsStruct($optionsObj);
            }
        } catch (\AssertionError $e) {
        }
        return $optionsObj;
    } catch (\Throwable $e) {
        throw new \Error(
            "Failed assigning options from config file",
            0,
            $e
        );
    }
}

function generatePublicOptionsStruct(Options $options): Options {
    $code = "return new class extends ".Options::class." {\n";
    foreach ((new \ReflectionClass($options))->getProperties() as $property) {
        $name = $property->getName();
        if ($name[0] !== "_" || $name[1] !== "_") {
            $code .= "\tpublic \${$name};\n";
        }
    }
    $code .= "};\n";
    $publicOptions = eval($code);
    foreach ($publicOptions as $option => $value) {
        $publicOptions->{$option} = $options->{$option};
    }

    return $publicOptions;
}

function buildVhost(Host $host, callable $bootLoader): Vhost {
    try {
        $hostExport = $host->export();
        $interfaces = $hostExport["interfaces"];
        $name = $hostExport["name"];
        $actions = $hostExport["actions"];

        $middlewares = [];
        $applications = [];
        $monitors = [];

        foreach ($actions as $key => $action) {
            if ($action instanceof Bootable) {
                $action = $bootLoader($action);
            } elseif (is_array($action) && isset($action[0]) && $action[0] instanceof Bootable) {
                $bootLoader($action[0]);
            }

            if ($action instanceof Middleware) {
                $middlewares[] = [$action, "process"];
            } elseif (\is_array($action) && isset($action[0]) && $action[0] instanceof Middleware) {
                $middlewares[] = [$action[0], "process"];
            }

            if ($action instanceof Monitor) {
                $monitors[get_class($action)][] = $action;
            } elseif (is_array($action) && isset($action[0]) && $action[0] instanceof Monitor) {
                $monitors[get_class($action[0])][] = $action[0];
            }

            if (is_callable($action)) {
                $applications[] = $action;
            }
        }

        if (empty($applications)) {
            $application = static function (): string {
                return "<html><body><h1>It works!</h1></body></html>";
            };
        } elseif (count($applications) === 1) {
            $application = current($applications);
        } else {
            // Observe the Server in our stateful multi-responder so if a shutdown triggers
            // while we're iterating over our coroutines we can send a 503 response. This
            // obviates the need for applications to pay attention to server state themselves.
            $application = $bootLoader(new class($applications) implements Bootable, ServerObserver {
                private $applications;
                private $isStopping = false;

                public function __construct(array $applications) {
                    $this->applications = $applications;
                }

                public function boot(Server $server, PsrLogger $logger) {
                    $server->attach($this);
                }

                public function update(Server $server): Promise {
                    if ($server->state() === Server::STOPPING) {
                        $this->isStopping = true;
                    }

                    return new Success;
                }

                public function __invoke(Request $request): \Generator {
                    foreach ($this->applications as $action) {
                        $response = yield call($action, $request);

                        if ($this->isStopping) {
                            $status = HTTP_STATUS["SERVICE_UNAVAILABLE"];
                            $reason = "Server shutting down";
                            return new Response\HtmlResponse(makeGenericBody($status), [], $status, $reason);
                        }

                        if ($response) {
                            return $response;
                        }
                    }

                    throw new \Error("No application returned a response"); // @TODO Error fallback handler
                }

                public function __debugInfo() {
                    return ["applications" => $this->applications];
                }
            });
        }

        $vhost = new Vhost($name, $interfaces, $application, $middlewares, $monitors, $hostExport["httpdriver"]);
        if ($crypto = $hostExport["crypto"]) {
            $vhost->setCrypto($crypto);
        }

        return $vhost;
    } catch (\Throwable $previousException) {
        throw new \Error(
            "Failed building Vhost instance",
            $code = 0,
            $previousException
        );
    }
}

function makeMiddlewareHandler(callable $application, array $middlewares): callable {
    return $next = coroutine(function (Request $request) use (&$next, &$middlewares, $application) {
        if (empty($middlewares)) {
            $response = yield call($application, $request);
            if (!$response instanceof Response) {
                throw new \Error("Applications must return an instance of " . Response::class);
            }
            return $response;
        }

        $middleware = \array_shift($middlewares);
        $response = yield call($middleware, $request, $next);
        if (!$response instanceof Response) {
            throw new \Error("Middlewares must return an instance of " . Response::class);
        }
        return $response;
    });
}

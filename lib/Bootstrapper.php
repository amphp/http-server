<?php

namespace Aerys;

use Amp\{ Reactor, Promise, Success, function any };
use Psr\Log\LoggerAwareInterface as PsrLoggerAware;

class Bootstrapper {
    private $hostAggregator;

    public function __construct(callable $hostAggregator = null) {
        $this->hostAggregator = $hostAggregator ?: ["\\Aerys\\Host", "getDefinitions"];
    }

    /**
     * Bootstrap a server from command line options
     *
     * @param \Amp\Reactor $reactor
     * @param \Aerys\Logger $logger
     * @param \Aerys\Console $console
     * @return \Aerys\Server
     */
    public function boot(Reactor $reactor, Logger $logger, Console $console): Server {
        $configFile = $this->selectConfigFile((string)$console->getArg("config"));
        $logger->info("Using config file found at $configFile");
        if (!include($configFile)) {
            throw new \DomainException(
                "Config file inclusion failure: {$configFile}"
            );
        } elseif (!defined("AERYS_OPTIONS")) {
            $options = [];
        } elseif (is_array(AERYS_OPTIONS)) {
            $options = AERYS_OPTIONS;
        } else {
            throw new \DomainException(
                "Invalid AERYS_OPTIONS constant: array expected, got " . gettype(AERYS_OPTIONS)
            );
        }
        if ($console->isArgDefined("debug")) {
            $options["debug"] = true;
        }

        $options = $this->generateOptionsObjFromArray($options);
        $hosts = \call_user_func($this->hostAggregator) ?: [new Host];
        $vhosts = new VhostContainer;
        foreach ($hosts as $host) {
            $vhost = $this->buildVhost($logger, $host);
            $vhosts->use($vhost);
        }
        $timeContext = new TimeContext($reactor, $logger);
        $server = new Server($reactor, $options, $vhosts, $logger, $timeContext);

        return $server;
    }

    private function selectConfigFile(string $configFile): string {
        if ($configFile !== "") {
            return realpath(is_dir($configFile) ? rtrim($configFile, "/") . "/config.php" : $configFile);
        }

        $paths = [
            __DIR__ . "/../config.php",
            __DIR__ . "/../etc/config.php",
            __DIR__ . "/../bin/config.php",
            "/etc/aerys/config.php",
        ];
        foreach ($paths as $path) {
            if (file_exists($path)) {
                return realpath($path);
            }
        }

        throw new \DomainException(
            "No config file found"
        );
    }

    private function generateOptionsObjFromArray(array $optionsArray): Options {
        try {
            $optionsObj = new Options;
            foreach ($optionsArray as $key => $value) {
                $optionsObj->{$key} = $value;
            }
            return $optionsObj->debug ? $optionsObj : $this->generatePublicOptionsStruct($optionsObj);
        } catch (\BaseException $e) {
            throw new \DomainException(
                "Failed assigning options from config file", 0, $e
            );
        }
    }

    private function generatePublicOptionsStruct(Options $options): Options {
        $code = "return new class extends \Aerys\Options {\n";
        foreach ((new \ReflectionClass($options))->getProperties() as $property) {
            $name = $property->getName();
            if ($name[0] !== "_") {
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

    private function buildVhost(Logger $logger, Host $host) {
        try {
            $hostExport = $host->export();
            $address = $hostExport["address"];
            $port = $hostExport["port"];
            $name = $hostExport["name"];
            $actions = $hostExport["actions"];
            $filters = $hostExport["filters"];
            $application = $this->buildApplication($logger, $actions);
            $vhost = new Vhost($name, $address, $port, $application, $filters);
            if ($crypto = $hostExport["crypto"]) {
                $vhost->setCrypto($crypto);
            }

            return $vhost;
        } catch (\BaseException $previousException) {
            throw new \DomainException(
                "Failed building Vhost instance",
                $code = 0,
                $previousException
            );
        }
    }

    private function buildApplication(Logger $logger, array $actions) {
        foreach ($actions as $key => $action) {
            if (!is_callable($action)) {
                throw new \DomainException(
                    "Application action at index {$key} is not callable"
                );
            }
            if ($action instanceof PsrLoggerAware) {
                $action->setLogger($logger);
            } elseif (is_array($action) && is_object($action[0]) && $action[0] instanceof PsrLoggerAware) {
                $action[0]->setLogger($logger);
            }
        }

        if (empty($actions)) {
            return function(Request $request, Response $response) {
                $response->end("<html><body><h1>It works!</h1></body></html>");
            };
        }

        if (count($actions) === 1) {
            return current($actions);
        }

        // We create a ServerObserver around our stateful multi-responder
        // so that if the server stops while we're iterating over our coroutines
        // we can send a 503 response. This prevents application responders from
        // ever needing to pay attention to the server's state themselves.
        $application = new class($actions) implements ServerObserver {
            private $actions;
            private $observers = [];
            private $isStopping = false;

            public function __construct(array $actions) {
                $this->actions = $actions;
                foreach ($this->actions as $action) {
                    if ($action instanceof ServerObserver) {
                        $this->observers[] = $action;
                    } elseif (is_array($action) && $action[0] instanceof ServerObserver) {
                        $this->observers[] = $action[0];
                    }
                }
            }

            public function update(Server $server): Promise {
                $observerPromises = [];
                foreach ($this->observers as $observer) {
                    $observerPromises[] = $observer->update($server);
                }
                if ($server->state() === Server::STOPPING) {
                    $this->isStopping = true;
                }

                return any($observerPromises);
            }

            public function __invoke(Request $request, Response $response) {
                foreach ($this->actions as $action) {
                    $out = ($action)($request, $response);
                    if ($out instanceof \Generator) {
                        yield from $out;
                    }
                    if ($response->state() & Response::STARTED) {
                        return;
                    }
                    if ($this->isStopping) {
                        $response->setStatus(HTTP_STATUS["SERVICE_UNAVAILABLE"]);
                        $response->setReason("Server shutting down");
                        $response->setHeader("Aerys-Generic-Response", "enable");
                        $response->end();
                        return;
                    }
                }
            }
        };

        return [$application, "__invoke"];
    }
}

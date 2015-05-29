<?php

namespace Aerys;

use Amp\{ Reactor, Promise, Success };
use League\CLImate\CLImate;
use Psr\Log\LoggerAwareInterface as PsrLoggerAware;

class Bootstrapper {
    /**
     * Bootstrap a server from command line options
     *
     * @param \Amp\Reactor $reactor
     * @param \Aerys\Logger $logger
     * @param array $cliArgs An array of command line arguments
     * @return array
     */
    public function boot(Reactor $reactor, Logger $logger, CLImate $climate): array {
        $configFile = $this->selectConfigFile((string)$climate->arguments->get("config"));
        $forceDebug = $climate->arguments->defined("debug");

        if (include($configFile)) {
            $hosts = Host::getDefinitions() ?: [new Host];
        } else {
            throw new \DomainException(
                "Config file inclusion failure: {$configFile}"
            );
        }

        if (!defined("AERYS_OPTIONS")) {
            $options = [];
        } elseif (is_array(AERYS_OPTIONS)) {
            $options = AERYS_OPTIONS;
        } else {
            throw new \DomainException(
                "Invalid AERYS_OPTIONS constant: array expected, got " . gettype(AERYS_OPTIONS)
            );
        }

        // Override the config file debug setting if indicated on the command line
        if ($forceDebug) {
            $options["debug"] = true;
        }

        $options = $this->generateOptionsObjFromArray($options);

        $server = new Server($reactor, $logger, $options->debug);
        $vhostGroup = new VhostGroup;
        foreach ($hosts as $host) {
            $vhost = $this->buildVhost($server, $logger, $host);
            $vhostGroup->addHost($vhost);
        }

        $addrCtxMap = [];
        $addresses = $vhostGroup->getBindableAddresses();
        $tlsBindings = $vhostGroup->getTlsBindingsByAddress();
        $backlogSize = $options->socketBacklogSize;
        $shouldReusePort = empty($options->debug);

        foreach ($addresses as $address) {
            $context = stream_context_create(["socket" => [
                "backlog"      => $backlogSize,
                "so_reuseport" => $shouldReusePort,
            ]]);
            if (isset($tlsBindings[$address])) {
                stream_context_set_option($context, ["ssl" => $tlsBindings[$address]]);
            }
            $addrCtxMap[$address] = $context;
        }

        $rfc7230Server = new Rfc7230Server($reactor, $vhostGroup, $options, $logger);
        $server->attach($rfc7230Server);

        return [$server, $options, $addrCtxMap, $rfc7230Server];
    }

    private function selectConfigFile(string $configFile): string {
        if ($configFile !== "") {
            return is_dir($configFile) ? rtrim($configFile, "/") . "/config.php" : $configFile;
        }

        $paths = [
            __DIR__ . "/../config.php",
            __DIR__ . "/../etc/config.php",
            __DIR__ . "/../bin/config.php",
            "/etc/aerys/config.php",
        ];
        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
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
        $code = "return new class extends \Aerys\Options {\n\tuse \Amp\Struct;\n";
        foreach ((new \ReflectionClass($options))->getProperties() as $property) {
            $name = $property->getName();
            $value = $options->{$name};
            $code .= "\tpublic \${$property} = " . var_export($value, true) . ";\n";
        }
        $code .= "};\n";

        return eval($code);
    }

    private function buildVhost(Server $server, Logger $logger, Host $host) {
        try {
            $hostExport = $host->export();
            $address = $hostExport["address"];
            $port = $hostExport["port"];
            $name = $hostExport["name"];
            $actions = $hostExport["actions"];
            $filters = $hostExport["filters"];
            $application = $this->buildApplication($server, $logger, $actions);
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

    private function buildApplication(Server $server, Logger $logger, array $actions) {
        foreach ($actions as $key => $action) {
            if (!is_callable($action)) {
                throw new \DomainException(
                    "Application action at index {$key} is not callable"
                );
            }
            if ($action instanceof ServerObserver) {
                $server->attach($action);
            } elseif (is_array($action) && is_object($action[0]) && $action[0] instanceof ServerObserver) {
                $server->attach($action[0]);
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
            private $isStopping = false;
            public function __construct(array $actions) {
                $this->actions = $actions;
            }
            public function update(\SplSubject $subject): Promise {
                if ($subject->state() === Server::STOPPING) {
                    $this->isStopping = true;
                }
                return new Success;
            }
            public function respond(Request $request, Response $response) {
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
                        $response->setHeader("Aerys-Generic-Response", "enable");
                        $response->end();
                        return;
                    }
                }
            }
        };

        $server->attach($application);

        return [$application, "respond"];
    }

}

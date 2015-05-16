<?php

namespace Aerys;

use Amp\Reactor;

class Bootstrapper {
    private $reactor;

    public function __construct(Reactor $reactor) {
        $this->reactor = $reactor;
    }

    /**
     * Parse server command line options
     *
     * Returns an array with the following keys:
     *
     *  - help
     *  - debug
     *  - config
     *  - workers
     *  - remote
     *
     * @return array
     */
    public static function parseCommandLineOptions() {
        $shortOpts = "hdc:w:r:";
        $longOpts = ["help", "debug", "config:", "workers:", "remote:"];
        $parsedOpts = getopt($shortOpts, $longOpts);
        $shortOptMap = [
            "c" => "config",
            "w" => "workers",
            "r" => "remote",
        ];

        $options = [
            "config" => "",
            "workers" => 0,
            "remote" => "",
        ];

        foreach ($parsedOpts as $key => $value) {
            $key = empty($shortOptMap[$key]) ? $key : $shortOptMap[$key];
            if (isset($options[$key])) {
                $options[$key] = $value;
            }
        }

        $options["debug"] = isset($parsedOpts["d"]) || isset($parsedOpts["debug"]);
        $options["help"] = isset($parsedOpts["h"]) || isset($parsedOpts["help"]);

        return $options;
    }

    /**
     * Bootstrap a server watcher from command line options
     *
     * @param string $configFile The server config file to bootstrap
     * @param bool $forceDebug Should the config file debug setting be overridden?
     * @return array [$server, $addrCtxMap, $onClient]
     */
    public function boot(string $configFile, bool $forceDebug): array {
        list($hosts, $options) = $this->loadConfigFile($configFile, $forceDebug);

        $server = new Server($this->reactor);

        $vhostGroup = new VhostGroup;
        foreach ($hosts as $host) {
            $vhost = $this->buildVhost($server, $host);
            $vhostGroup->addHost($vhost);
        }

        $addressContextMap = [];
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
            $addressContextMap[$address] = $context;
        }

        $rfc7230Server = new Rfc7230Server($this->reactor, $vhostGroup, $options);
        $server->attach($rfc7230Server);

        return [$server, $addressContextMap, [$rfc7230Server, "import"]];
    }

    private function loadConfigFile(string $configFile, bool $forceDebug): array {
        if (empty($configFile)) {
            $hosts = [new Host];
        } elseif (include($configFile)) {
            $hosts = Host::getDefinitions() ?: [new Host];
        } else {
            throw new \DomainException(
                "Config file inclusion failure: {$configFile}"
            );
        }

        if (!defined("AERYS_OPTIONS")) {
            $optionsArray = [];
        } elseif (is_array(AERYS_OPTIONS)) {
            $optionsArray = AERYS_OPTIONS;
        } else {
            throw new \DomainException(
                "Invalid AERYS_OPTIONS constant: array expected, got " . gettype(AERYS_OPTIONS)
            );
        }

        $options = $this->generateOptions($optionsArray, $forceDebug);

        return [$hosts, $options];
    }

    /**
     * When in debug mode the server will bootstrap the normal Options instance with
     * access/assignment verification. If debug mode is disabled a duplicate object
     * with public properties is used to maximize performance in production.
     */
    private function generateOptions(array $optionsArray, bool $forceDebug): Options {
        try {
            $optionsObj = new Options;
            foreach ($optionsArray as $key => $value) {
                $optionsObj->{$key} = $value;
            }

            // The CLI debug switch always overrides the config file setting
            if ($forceDebug) {
                $optionsObj->debug = true;
            }

            return $optionsObj->debug
                ? $optionsObj
                : $this->generatePublicOptionsStruct($optionsObj);

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

    private function buildVhost(Server $server, Host $host) {
        try {
            $hostExport = $host->export();
            $address = $hostExport["address"];
            $port = $hostExport["port"];
            $name = $hostExport["name"];
            $actions = $hostExport["actions"];
            $filters = $hostExport["filters"];
            $application = $this->buildApplication($server, $actions);
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

    private function buildApplication(Server $server, array $actions) {
        foreach ($actions as $key => $action) {
            if (!is_callable($action)) {
                throw new \DomainException(
                    "Application action at index {$key} is not callable"
                );
            }
            if ($action instanceof ServerObserver) {
                $server->attach($action);
            }
        }

        switch (count($actions)) {
            case 0:
                return $this->buildDefaultApplication();
            case 1:
                return current($actions);
            default:
                return $this->buildMultiActionApplication($actions);
        }
    }

    private function buildDefaultApplication() {
        return function(Request $request, Response $response) {
            $response->end("<html><body><h1>It works!</h1></body></html>");
        };
    }

    private function buildMultiActionApplication(array $actions) {
        return function(Request $request, Response $response) use ($actions): \Generator {
            foreach ($actions as $action) {
                $result = ($action)($request, $response);
                if ($result instanceof \Generator) {
                    yield from $result;
                }
                if ($response->state() & Response::STARTED) {
                    return;
                }
            }
        };
    }
}

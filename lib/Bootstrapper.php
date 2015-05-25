<?php

namespace Aerys;

use Amp\Reactor;

class Bootstrapper {

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
    public static function parseCommandLineArgs() {
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
     * @param \Amp\Reactor $reactor
     * @param array $cliArgs An array of command line arguments
     * @param array $observers An array of observers to attach to the bootstrapped Server
     * @return \Generator
     */
    public static function boot(Reactor $reactor, array $cliArgs, array $observers = []): \Generator {
        $configFile = $cliArgs["config"];
        $forceDebug = $cliArgs["debug"];

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

        $options = self::generateOptionsObjFromArray($options);

        $server = new Server($reactor, $options["debug"]);
        $vhostGroup = new VhostGroup;
        foreach ($hosts as $host) {
            $vhost = self::buildVhost($server, $host);
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

        $observers[] = $rfc7230Server = new Rfc7230Server($reactor, $vhostGroup, $options);
        // @TODO Instantiate the HTTP/2.0 server here.
        // This will be attached to the Rfc7230Server instance in some way because
        // h2 requests are initially negotiated via an HTTP/1.1 Upgrade request or
        // through the TLS handshake executed by the Rfc7230Server.
        foreach ($observers as $observer) {
            $server->attach($observer);
        }

        yield $server->start($addressContextMap, [$rfc7230Server, "import"]);

        return $server;
    }

    private static function generateOptionsObjFromArray(array $optionsArray): Options {
        try {
            $optionsObj = new Options;
            foreach ($optionsArray as $key => $value) {
                $optionsObj->{$key} = $value;
            }
            return $optionsObj->debug ? $optionsObj : self::generatePublicOptionsStruct($optionsObj);
        } catch (\BaseException $e) {
            throw new \DomainException(
                "Failed assigning options from config file", 0, $e
            );
        }
    }

    private static function generatePublicOptionsStruct(Options $options): Options {
        $code = "return new class extends \Aerys\Options {\n\tuse \Amp\Struct;\n";
        foreach ((new \ReflectionClass($options))->getProperties() as $property) {
            $name = $property->getName();
            $value = $options->{$name};
            $code .= "\tpublic \${$property} = " . var_export($value, true) . ";\n";
        }
        $code .= "};\n";

        return eval($code);
    }

    private static function buildVhost(Server $server, Host $host) {
        try {
            $hostExport = $host->export();
            $address = $hostExport["address"];
            $port = $hostExport["port"];
            $name = $hostExport["name"];
            $actions = $hostExport["actions"];
            $filters = $hostExport["filters"];
            $application = self::buildApplication($server, $actions);
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

    private static function buildApplication(Server $server, array $actions) {
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
                return function(Request $request, Response $response) {
                    $response->end("<html><body><h1>It works!</h1></body></html>");
                };
            case 1:
                return current($actions);
            default:
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

}

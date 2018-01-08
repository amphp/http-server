<?php

namespace Aerys\Internal;

use Aerys\Bootable;
use Aerys\CallableResponder;
use Aerys\Console;
use Aerys\Host;
use Aerys\Middleware;
use Aerys\Monitor;
use Aerys\Options;
use Aerys\Responder;
use Aerys\Response;
use Aerys\Server;
use Aerys\TryResponder;
use Psr\Log\LoggerInterface as PsrLogger;
use function Aerys\initServer;
use function Aerys\selectConfigFile;
use function Amp\call;

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
    $host = yield call(function () use (&$logger, $console, $configFile) {
        return include $configFile;
    });

    $logger->info("Using config file found at $configFile");

    if (!$host instanceof Host) {
        throw new \Error(
            "Config file at $configFile did not return an instance of " . Host::class
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

    return new Server($host, generateOptionsObjFromArray($options), $logger);
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

        $responders = [];
        $middlewares = [];
        $monitors = [];

        foreach ($actions as $key => $action) {
            if ($action instanceof Bootable) {
                $action = $bootLoader($action);
            }

            if ($action instanceof Middleware) {
                $middlewares[] = $action;
            }

            if ($action instanceof Monitor) {
                $monitors[\get_class($action)][] = $action;
            }

            if (\is_callable($action)) {
                $action = new CallableResponder($action);
            }

            if ($action instanceof Responder) {
                $responders[] = $action;
            }
        }

        if (empty($responders)) {
            $responder = new CallableResponder(static function (): Response {
                return new Response\HtmlResponse("<html><body><h1>It works!</h1></body>");
            });
        } elseif (\count($responders) === 1) {
            $responder = $responders[0];
        } else {
            $responder = new TryResponder;
            foreach ($responders as $action) {
                $responder->addResponder($action);
            }
        }

        $vhost = new Vhost($name, $interfaces, $responder, $middlewares, $monitors);
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

function makeMiddlewareResponder(Responder $responder, array $middlewares): Responder {
    if (empty($middlewares)) {
        return $responder;
    }

    $middleware = \end($middlewares);

    while ($middleware) {
        $responder = new MiddlewareResponder($middleware, $responder);
        $middleware = \prev($middlewares);
    }

    return $responder;
}

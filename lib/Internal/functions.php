<?php

namespace Aerys\Internal;

use Aerys\Bootable;
use Aerys\Console;
use Aerys\Delegate;
use Aerys\Host;
use Aerys\Middleware;
use Aerys\Monitor;
use Aerys\Options;
use Aerys\Responder;
use Aerys\Response;
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

        $delegates = [];
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
                $action = new ConstantDelegate($action);
            }

            if ($action instanceof Delegate) {
                $delegates[] = $action;
            }
        }

        if (empty($delegates)) {
            $responder = new CallableResponder(static function (): Response {
                return new Response\HtmlResponse("<html><body><h1>It works!</h1></body>");
            });
        } else {
            $responder = new DelegateCollection($delegates);
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

    $current = \end($middlewares);

    while ($current) {
        $responder = new MiddlewareResponder($current, $responder);
        $current = \prev($middlewares);
    }

    return $responder;
}

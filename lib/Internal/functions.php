<?php

namespace Aerys\Internal;

use Aerys\Console;
use Aerys\Options;
use Aerys\Server;
use Psr\Log\LoggerInterface as PsrLogger;
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

    // Protect current scope by requiring script within another function.
    $initializer = (function () use ($configFile) {
        return require $configFile;
    })();

    $logger->info("Using config file found at $configFile");

    if (!\is_callable($initializer)) {
        throw new \Error("The config file at $configFile must return a callable");
    }

    try {
        $server = yield call($initializer, $logger, $console);
    } catch (\Throwable $exception) {
        throw new \Error(
            "Callable invoked from file at $configFile threw an exception",
            0,
            $exception
        );
    }

    if (!$server instanceof Server) {
        throw new \Error(
            "Callable invoked from file at $configFile did not return an instance of " . Server::class
        );
    }

    return $server;
}

/**
 * Gives the absolute path of a config file.
 *
 * @param string $configFile path to config file used by Aerys instance
 *
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

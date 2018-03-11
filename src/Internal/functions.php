<?php

namespace Amp\Http\Server\Internal;

use Amp\Http\Server\Console;
use Amp\Http\Server\Options;
use Amp\Http\Server\Server;
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

    $options = new Options;

    if ($console->isArgDefined("debug")) {
        $options = $options->withDebugMode();
    }

    try {
        $server = yield call($initializer, $options, $logger, $console);
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

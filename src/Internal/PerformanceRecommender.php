<?php

namespace Amp\Http\Server\Internal;

use Amp\Http\Server\HttpServer;
use Amp\Http\Server\ServerObserver;
use Amp\Promise;
use Amp\Success;

final class PerformanceRecommender implements ServerObserver
{
    /** @inheritdoc */
    public function onStart(HttpServer $server): Promise
    {
        $logger = $server->getLogger();

        if ($server->getOptions()->isInDebugMode()) {
            if (\ini_get("zend.assertions") !== "1") {
                $logger->warning(
                    "Running in debug mode without assertions enabled will not generate debug level " .
                    "log messages. Enable assertions in php.ini (zend.assertions = 1) to enable " .
                    "debug logging."
                );
            }
        } else {
            if (\ini_get("zend.assertions") === "1") {
                $logger->warning(
                    "Running in production with assertions enabled is not recommended; it has a negative impact " .
                    "on performance. Disable assertions in php.ini (zend.assertions = -1) for best performance " .
                    "or set the debug mode option to hide this warning."
                );
            }

            if (\extension_loaded("xdebug")) {
                $logger->warning("The 'xdebug' extension is loaded, which has a major impact on performance.");
            }
        }

        return new Success;
    }

    /** @inheritdoc */
    public function onStop(HttpServer $server): Promise
    {
        return new Success;
    }
}

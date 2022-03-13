<?php

namespace Amp\Http\Server\Internal;

use Amp\Http\Server\HttpServer;

final class PerformanceRecommender
{
    public function onStart(HttpServer $server): void
    {
        $logger = $server->getLogger();

        if ($server->getOptions()->isInDebugMode()) {
            if (\ini_get("zend.assertions") !== "1") {
                $logger->warning(
                    "Running in debug mode without assertions enabled will not generate debug level " .
                    "log messages. Enable assertions in php.ini (zend.assertions = 1) to enable" .
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
    }
}

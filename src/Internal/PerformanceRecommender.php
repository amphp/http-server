<?php

namespace Amp\Http\Server\Internal;

use Amp\Http\Server\HttpServer;

/** @internal */
final class PerformanceRecommender
{
    public function onStart(HttpServer $server): void
    {
        $logger = $server->getLogger();

        if (\ini_get("zend.assertions") === "1") {
            $logger->warning(
                "Running in production with assertions enabled is not recommended; it has a negative impact " .
                "on performance. Disable assertions in php.ini (zend.assertions = -1) for best performance."
            );
        }

        if (\extension_loaded("xdebug")) {
            $logger->warning("The 'xdebug' extension is loaded, which has a major impact on performance.");
        }
    }
}

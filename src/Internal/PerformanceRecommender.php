<?php declare(strict_types=1);

namespace Amp\Http\Server\Internal;

use Psr\Log\LoggerInterface as PsrLogger;

/** @internal */
final class PerformanceRecommender
{
    public function __construct(
        private readonly PsrLogger $logger,
    ) {
    }

    public function onStart(): void
    {
        if (\ini_get("zend.assertions") === "1") {
            $this->logger->warning(
                "Running in production with assertions enabled is not recommended; it has a negative impact " .
                "on performance. Disable assertions in php.ini (zend.assertions = -1) for best performance."
            );
        }

        if (\extension_loaded("xdebug")) {
            $this->logger->warning("The 'xdebug' extension is loaded, which has a major impact on performance.");
        }
    }
}

<?php

namespace Amp\Http\Server\Support;

use Amp\Http\Server\Server;
use Amp\Http\Server\ServerObserver;
use Amp\Promise;
use Amp\Success;

final class Recommender implements ServerObserver {
    /** @inheritdoc */
    public function onStart(Server $server): Promise {
        $logger = $server->getLogger();

        if (\extension_loaded('xdebug')) {
            $logger->warning('The "xdebug" extension is loaded, this has a major impact on performance.');
        }
        try {
            if (!@\assert(false)) {
                $logger->warning("Assertions are enabled, this has a major impact on performance.");
            }
        } catch (\AssertionError $exception) {
            $logger->warning("Assertions are enabled, this has a major impact on performance.");
        }

        return new Success;
    }

    /** @inheritdoc */
    public function onStop(Server $server): Promise {
        return new Success;
    }
}

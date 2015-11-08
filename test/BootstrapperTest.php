<?php

namespace Aerys\Test;

use Aerys\Bootstrapper;
use Aerys\Console;
use Aerys\Logger;
use function Amp\resolve;
use function Amp\wait;

class BootstrapperTest extends \PHPUnit_Framework_TestCase {
    /**
     * @expectedException \DomainException
     * @expectedExceptionMessage No config file found, specify one via the -c switch on command line
     */
    public function testThrowsWithoutConfig() {
        $bootstrapper = new Bootstrapper(function() {
            return [];
        });

        $logger = new class extends Logger {
            protected function output(string $message) {
                // do nothing
            }
        };

        wait(resolve($bootstrapper->boot($logger, new Console)));
    }
}
<?php

namespace Aerys\Test;

use Aerys\Console;
use Aerys\Internal;
use Aerys\Logger;
use Amp\Coroutine;
use League\CLImate\CLImate;
use PHPUnit\Framework\TestCase;
use function Amp\Promise\wait;

class bootServerTest extends TestCase {
    /**
     * @expectedException \Error
     * @expectedExceptionMessage No config file found, specify one via the -c switch on command line
     */
    public function testThrowsWithoutConfig() {
        $logger = new class extends Logger {
            protected function output(string $message) {
                // do nothing
            }
        };

        wait(new Coroutine(Internal\bootServer($logger, new Console(new CLImate))));
    }

    public function testBoot() {
        $logger = new class extends Logger {
            protected function output(string $message) {
                // do nothing
            }
        };

        $console = new class($this) extends Console {
            const ARGS = [
                "config" => __DIR__ . "/testBootServerInclude.php",
            ];
            private $test;
            public function __construct($test) {
                $this->test = $test;
            }
            public function output(string $msg) {
                $this->test->fail("Shouldn't be reached here");
            }
            public function forceAnsiOn() {
            }
            public function isArgDefined(string $arg) {
                return isset(self::ARGS[$arg]);
            }
            public function getArg(string $arg) {
                $this->test->assertTrue(isset(self::ARGS[$arg]));
                return self::ARGS[$arg];
            }
        };

        $server = wait(new Coroutine(Internal\bootServer($logger, $console)));

        $info = $server->__debugInfo();
        if (Internal\Host::separateIPv4Binding()) {
            $this->assertEquals(["tcp://0.0.0.0:80", "tcp://[::]:80"], array_values($info["host"]->getAddresses()));
        } else {
            $this->assertEquals(["tcp://[::]:80"], array_values($info["host"]->getAddresses()));
        }
    }
}

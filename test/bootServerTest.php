<?php

namespace Aerys\Test;

use Aerys\Client;
use Aerys\Console;
use Aerys\Host;
use Aerys\Internal;
use Aerys\InternalRequest;
use Aerys\Logger;
use Aerys\Request;
use Aerys\StandardResponse;
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
        if (Host::separateIPv4Binding()) {
            $this->assertEquals(["tcp://[::]:443", "tcp://0.0.0.0:443", "tcp://127.0.0.1:80"], array_values($info["vhosts"]->getBindableAddresses()));
        } else {
            $this->assertEquals(["tcp://[::]:443", "tcp://127.0.0.1:80"], array_values($info["vhosts"]->getBindableAddresses()));
        }
        $this->assertEquals(strtr($console::ARGS["config"], "\\", "/"), strtr($server->getOption("configPath"), "\\", "/"));
        $this->assertEquals(5000, $server->getOption("shutdownTimeout")); // custom option test

        $vhosts = $info["vhosts"]->__debugInfo()["vhosts"];
        $this->assertEquals(["localhost:443:[::]:443", "localhost:443:0.0.0.0:443", "example.com:80:127.0.0.1:80", "foo.bar:80:127.0.0.1:80"], array_keys($vhosts));
        $this->assertInternalType('callable', $vhosts["localhost:443:0.0.0.0:443"]->getApplication());
        $filter = current($vhosts["example.com:80:127.0.0.1:80"]->getFilters());
        $this->assertInstanceOf("OurFilter", $filter[0]);
        $this->assertEquals("filter", $filter[1]);
        $this->assertInstanceOf("OurFilter", $vhosts["example.com:80:127.0.0.1:80"]->getApplication());
        $this->assertEquals(2, count($vhosts["foo.bar:80:127.0.0.1:80"]->getApplication()->__debugInfo()["applications"]));
        $vhosts["foo.bar:80:127.0.0.1:80"]->getApplication()(new Request($ireq = new InternalRequest), new StandardResponse((function () {yield;})(), new Client))->next();
        $this->assertEquals(["responder" => 1, "foo.bar" => 1], $ireq->locals);
    }

    // initServer() is essentially already covered by the previous test in detail, just checking if it works at all here.
    public function testInit() {
        $logger = new class extends Logger {
            protected function output(string $message) {
                // do nothing
            }
        };

        $server = \Aerys\initServer($logger, [(new Host)->name("foo.bar")]);
        $vhosts = $server->__debugInfo()["vhosts"]->__debugInfo()["vhosts"];
        $this->assertEquals("foo.bar:80:[::]:80", key($vhosts));
    }
}

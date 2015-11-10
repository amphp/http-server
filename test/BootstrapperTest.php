<?php

namespace Aerys\Test;

use Aerys\Bootstrapper;
use Aerys\Console;
use Aerys\InternalRequest;
use Aerys\Logger;
use Aerys\StandardRequest;
use Aerys\StandardResponse;
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

    public function testBootstrap() {
        $bootstrapper = new Bootstrapper;

        $logger = new class extends Logger {
            protected function output(string $message) {
                // do nothing
            }
        };

        $console = new class($this) extends Console {
            const ARGS = [
                "config" => __DIR__."/TestBootstrapperInclude.php",
            ];
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function output(string $msg) { $this->test->fail("Shoudln't be reached here"); }
            public function addArgs(array $args) { $this->test->fail("Shoudln't be reached here"); }
            public function forceAnsiOn() { }
            public function isArgDefined(string $arg) {
                return isset(self::ARGS[$arg]);
            }
            public function getArg(string $arg) {
                $this->test->assertTrue(isset(self::ARGS[$arg]));
                return self::ARGS[$arg];
            }
        };

        $server = wait(resolve($bootstrapper->boot($logger, $console)));


        $info = $server->__debugInfo();
        $this->assertEquals(["tcp://[::]:443", "tcp://0.0.0.0:443", "tcp://127.0.0.1:80"], $info["vhosts"]->getBindableAddresses());
        $this->assertEquals($console::ARGS["config"], $server->getOption("configPath"));
        $this->assertEquals(5000, $server->getOption("shutdownTimeout")); // custom option test

        $vhosts = $info["vhosts"]->__debugInfo()["vhosts"];
        $this->assertEquals(["localhost:443", "example.com:80", "foo.bar:80"], array_keys($vhosts));
        $this->assertTrue(is_callable($vhosts["localhost:443"]->getApplication()));
        $middleware = current($vhosts["example.com:80"]->getFilters());
        $this->assertInstanceOf("OurMiddleware", $middleware[0]);
        $this->assertEquals("do", $middleware[1]);
        $this->assertInstanceOf("OurMiddleware", $vhosts["example.com:80"]->getApplication());
        $this->assertEquals(2, count($vhosts["foo.bar:80"]->getApplication()->__debugInfo()["applications"]));
        $vhosts["foo.bar:80"]->getApplication()(new StandardRequest($ireq = new InternalRequest), new StandardResponse((function(){yield;})()))->next();
        $this->assertEquals(["responder" => 1, "foo.bar" => 1], $ireq->locals);
    }
}
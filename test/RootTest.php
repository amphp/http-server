<?php

namespace Aerys\Test;

use Aerys\Server;
use Aerys\ServerObserver;
use Amp\File as file;

class RootTest extends \PHPUnit_Framework_TestCase {
    private static function fixturePath() {
        return \sys_get_temp_dir() . "/aerys_root_test_fixture";
    }

    /**
     * Setup a directory we can use as the document root
     */
    public static function setUpBeforeClass() {
        $fixtureDir = self::fixturePath();
        if (!file_exists($fixtureDir) && !\mkdir($fixtureDir)) {
            throw new \RuntimeException(
                "Failed creating temporary test fixture directory: {$fixtureDir}"
            );
        }
        if (!file_exists($fixtureDir. "/dir") &&!\mkdir($fixtureDir . "/dir")) {
            throw new \RuntimeException(
                "Failed creating temporary test fixture directory"
            );
        }
        if (!\file_put_contents($fixtureDir . "/test.txt", "test")) {
            throw new \RuntimeException(
                "Failed creating temporary test fixture file"
            );
        }
    }

    public static function tearDownAfterClass() {
        $fixtureDir = self::fixturePath();
        if (!@\file_exists($fixtureDir)) {
            return;
        }
        if (\stripos(\PHP_OS, "win") === 0) {
            \system('rd /Q /S "' . $fixtureDir . '"');
        } else {
            \system('/bin/rm -rf ' . \escapeshellarg($fixtureDir));
        }
    }

    /**
     * @dataProvider provideBadDocRoots
     * @expectedException \DomainException
     * @expectedExceptionMessage Document root requires a readable directory
     */
    public function testConstructorThrowsOnInvalidDocRoot($badPath) {
        $filesystem = $this->getMock('Amp\File\Driver');
        $root = new \Aerys\Root($badPath, $filesystem);
    }

    public function provideBadDocRoots() {
        return [
            [self::fixturePath() . "/some-dir-that-doesnt-exist"],
            [self::fixturePath() . "/test.txt"],
        ];
    }

    /**
     * @dataProvider provideRelativePathsAboveRoot
     */
    public function testForbiddenResponseOnRelativePathAboveRoot($relativePath) {
        $filesystem = $this->getMock('Amp\File\Driver');
        $root = new \Aerys\Root(self::fixturePath(), $filesystem);
        $request = $this->getMock("Aerys\\Request");
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue($relativePath))
        ;
        $response = $this->getMock("Aerys\\Response");
        $response->expects($this->once())
            ->method("setStatus")
            ->with(\Aerys\HTTP_STATUS["FORBIDDEN"])
        ;
        $root->__invoke($request, $response);
    }

    public function provideRelativePathsAboveRoot() {
        return [
            ["/../../../test.txt"],
            ["/dir/../../"],
        ];
    }

    public function testBasicFileResponse() {
        $root = new \Aerys\Root(self::fixturePath());
        $request = $this->getMock('Aerys\Request');
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue("/test.txt"))
        ;
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"))
        ;
        $response = $this->getMock('Aerys\Response');
        $response->expects($this->once())
            ->method("end")
            ->with("test") // <-- the contents of the /test.txt fixture file
        ;
        $generator = $root->__invoke($request, $response);
        $promise = \Amp\resolve($generator);
        \Amp\wait($promise);

        // Return so we can test cached responses in the next case
        return $root;
    }

    /**
     * @depends testBasicFileResponse
     */
    public function testCachedResponse($root) {
        $request = $this->getMock('Aerys\Request');
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue("/test.txt"))
        ;
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"))
        ;
        $response = $this->getMock('Aerys\Response');
        $response->expects($this->once())
            ->method("end")
            ->with("test") // <-- the contents of the /test.txt fixture file
        ;
        $root->__invoke($request, $response);
    }

    /**
     * @depends testBasicFileResponse
     */
    public function testDebugModeIgnoresCacheIfCacheControlHeaderIndicatesToDoSo($root) {
        $server = new class extends Server {
            function __construct() {}
            function attach(ServerObserver $obj){}
            function detach(ServerObserver $obj){}
            function getOption(string $option) { return true; }
            function state(): int { return Server::STARTED; }
        };
        $root->update($server);

        $request = $this->getMock('Aerys\Request');
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue("/test.txt"))
        ;
        $request->expects($this->once())
            ->method("getHeaderArray")
            ->will($this->returnValue(["no-cache"]))
        ;
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"))
        ;
        $response = $this->getMock('Aerys\Response');
        $response->expects($this->once())
            ->method("end")
            ->with("test") // <-- the contents of the /test.txt fixture file
        ;
        $generator = $root->__invoke($request, $response);
        $promise = \Amp\resolve($generator);
        \Amp\wait($promise);

        return $root;
    }

    /**
     * @depends testDebugModeIgnoresCacheIfCacheControlHeaderIndicatesToDoSo
     */
    public function testDebugModeIgnoresCacheIfPragmaHeaderIndicatesToDoSo($root) {
        $request = $this->getMock('Aerys\Request');
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue("/test.txt"))
        ;
        $request->expects($this->exactly(2))
            ->method("getHeaderArray")
            ->will($this->onConsecutiveCalls([], ["no-cache"]))
        ;
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"))
        ;
        $response = $this->getMock('Aerys\Response');
        $response->expects($this->once())
            ->method("end")
            ->with("test") // <-- the contents of the /test.txt fixture file
        ;
        $generator = $root->__invoke($request, $response);
        $promise = \Amp\resolve($generator);
        \Amp\wait($promise);

        return $root;
    }
}

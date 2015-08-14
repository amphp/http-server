<?php

namespace Aerys\Test;

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
        if (!\mkdir($fixtureDir, $mode = 0777, $recursive = true)) {
            throw new \RuntimeException(
                "Failed creating temporary test fixture directory: {$fixtureDir}"
            );
        }
        if (!\mkdir($fixtureDir . "/dir", $mode = 0777, $recursive = true)) {
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
        $reactor = $this->getMock("Amp\Reactor");
        $filesystem = $this->getMock("Amp\File\Driver");
        $root = new \Aerys\Root($badPath, $filesystem, $reactor);
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
        $reactor = $this->getMock("Amp\Reactor");
        $filesystem = $this->getMock("Amp\File\Driver");
        $root = new \Aerys\Root(self::fixturePath(), $filesystem, $reactor);
        $request = $this->getMock("Aerys\Request");
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue($relativePath))
        ;
        $response = $this->getMock("Aerys\Response");
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
}









































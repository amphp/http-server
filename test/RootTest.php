<?php

namespace Aerys\Test;

use Aerys\DefaultErrorHandler;
use Aerys\ErrorHandler;
use Aerys\Logger;
use Aerys\Options;
use Aerys\Request;
use Aerys\Root;
use Aerys\Server;
use Amp\Http\Status;
use Amp\Loop;
use Amp\Promise;
use Amp\Uri\Uri;
use PHPUnit\Framework\TestCase;

class RootTest extends TestCase {
    /** @var \Amp\Loop\Driver */
    private static $loop;

    private static function fixturePath() {
        return \sys_get_temp_dir() . "/aerys_root_test_fixture";
    }

    /**
     * Setup a directory we can use as the document root.
     */
    public static function setUpBeforeClass() {
        self::$loop = Loop::get();

        $fixtureDir = self::fixturePath();
        if (!file_exists($fixtureDir) && !\mkdir($fixtureDir)) {
            throw new \RuntimeException(
                "Failed creating temporary test fixture directory: {$fixtureDir}"
            );
        }
        if (!file_exists($fixtureDir. "/dir") && !\mkdir($fixtureDir . "/dir")) {
            throw new \RuntimeException(
                "Failed creating temporary test fixture directory"
            );
        }
        if (!\file_put_contents($fixtureDir . "/index.htm", "test")) {
            throw new \RuntimeException(
                "Failed creating temporary test fixture file"
            );
        }
        if (!\file_put_contents($fixtureDir . "/svg.svg", "<svg></svg>")) {
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
            \system('/usr/bin/env rm -rf ' . \escapeshellarg($fixtureDir));
        }
    }

    /**
     * Restore original loop driver instance as data providers require the same driver instance to be active as when
     * the data was generated.
     */
    public function setUp() {
        Loop::set(self::$loop);
    }

    /**
     * @dataProvider provideBadDocRoots
     * @expectedException \Error
     * @expectedExceptionMessage Document root requires a readable directory
     */
    public function testConstructorThrowsOnInvalidDocRoot($badPath) {
        $filesystem = $this->createMock('Amp\File\Driver');
        $root = new Root($badPath, $filesystem);
    }

    public function provideBadDocRoots() {
        return [
            [self::fixturePath() . "/some-dir-that-doesnt-exist"],
            [self::fixturePath() . "/index.htm"],
        ];
    }

    public function testBasicFileResponse() {
        $root = new Root(self::fixturePath());

        $server = $this->createMock(Server::class);
        $server->method('getOptions')
            ->willReturn((new Options)->withDebugMode(true));

        $root->onStart($server, $this->createMock(Logger::class), new DefaultErrorHandler);

        foreach ([
            ["/", "test"],
            ["/index.htm", "test"],
            ["/dir/../dir//..//././index.htm", "test"],
        ] as list($path, $contents)) {
            $request = $this->createMock(Request::class);
            $request->expects($this->once())
                ->method("getUri")
                ->will($this->returnValue(new Uri($path)));
            $request->expects($this->any())
                ->method("getMethod")
                ->will($this->returnValue("GET"));

            $promise = $root->respond($request);
            /** @var \Aerys\Response $response */
            $response = Promise\wait($promise);

            $this->assertSame("text/html; charset=utf-8", $response->getHeader("content-type"));
            $stream = $response->getBody();
            $this->assertSame($contents, Promise\wait($stream->read()));
        }

        // Return so we can test cached responses in the next case
        return $root;
    }

    /**
     * @depends testBasicFileResponse
     * @dataProvider provideRelativePathsAboveRoot
     */
    public function testPathsOnRelativePathAboveRoot(string $relativePath, Root $root) {
        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue(new Uri($relativePath)));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"));

        $promise = $root->respond($request);
        /** @var \Aerys\Response $response */
        $response = Promise\wait($promise);
        $stream = $response->getBody();
        $this->assertSame("test", Promise\wait($stream->read()));
    }

    public function provideRelativePathsAboveRoot() {
        return [
            ["/../../../index.htm"],
            ["/dir/../../"],
        ];
    }

    /**
     * @depends testBasicFileResponse
     * @dataProvider provideUnavailablePathsAboveRoot
     */
    public function testUnavailablePathsOnRelativePathAboveRoot(string $relativePath, Root $root) {
        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue(new Uri($relativePath)));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"));

        $promise = $root->respond($request);
        /** @var \Aerys\Response $response */
        $response = Promise\wait($promise);
        $this->assertSame(Status::NOT_FOUND, $response->getStatus());
    }

    public function provideUnavailablePathsAboveRoot() {
        return [
            ["/../aerys_root_test_fixture/index.htm"],
            ["/aerys_root_test_fixture/../../aerys_root_test_fixture"],
        ];
    }

    /**
     * @depends testBasicFileResponse
     */
    public function testCachedResponse(Root $root) {
        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue(new Uri("/index.htm")));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"));

        $promise = $root->respond($request);
        /** @var \Aerys\Response $response */
        $response = Promise\wait($promise);

        $this->assertSame("text/html; charset=utf-8", $response->getHeader("content-type"));
        $stream = $response->getBody();
        $this->assertSame("test", Promise\wait($stream->read()));
    }

    /**
     * @depends testBasicFileResponse
     */
    public function testDebugModeIgnoresCacheIfCacheControlHeaderIndicatesToDoSo(Root $root) {
        $server = $this->createMock(Server::class);
        $server->method('getOptions')
            ->willReturn((new Options)->withDebugMode(true));

        $root->onStart($server, $this->createMock(Logger::class), $this->createMock(ErrorHandler::class));

        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue(new Uri("/index.htm")));
        $request->expects($this->once())
            ->method("getHeaderArray")
            ->will($this->returnValue(["no-cache"]));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"));

        $promise = $root->respond($request);
        /** @var \Aerys\Response $response */
        $response = Promise\wait($promise);

        $this->assertSame("text/html; charset=utf-8", $response->getHeader("content-type"));
        $stream = $response->getBody();
        $this->assertSame("test", Promise\wait($stream->read()));

        return $root;
    }

    /**
     * @depends testDebugModeIgnoresCacheIfCacheControlHeaderIndicatesToDoSo
     */
    public function testDebugModeIgnoresCacheIfPragmaHeaderIndicatesToDoSo(Root $root) {
        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue(new Uri("/index.htm")));
        $request->expects($this->exactly(2))
            ->method("getHeaderArray")
            ->will($this->onConsecutiveCalls([], ["no-cache"]));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"));

        $promise = $root->respond($request);
        /** @var \Aerys\Response $response */
        $response = Promise\wait($promise);

        $this->assertSame("text/html; charset=utf-8", $response->getHeader("content-type"));
        $stream = $response->getBody();
        $this->assertSame("test", Promise\wait($stream->read()));

        return $root;
    }

    public function testOptionsHeader() {
        $root = new Root(self::fixturePath());
        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue(new Uri("/")));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("OPTIONS"));

        $promise = $root->respond($request);
        /** @var \Aerys\Response $response */
        $response = Promise\wait($promise);

        $this->assertSame("GET, HEAD, OPTIONS", $response->getHeader('allow'));
        $this->assertSame("bytes", $response->getHeader('accept-ranges'));
    }

    public function testPreconditionFailure() {
        $root = new Root(self::fixturePath());

        $server = $this->createMock(Server::class);
        $server->method('getOptions')
            ->willReturn((new Options)->withDebugMode(true));

        $root->onStart($server, $this->createMock(Logger::class), new DefaultErrorHandler);

        $root->setOption("useEtagInode", false);
        $diskPath = \realpath(self::fixturePath())."/index.htm";
        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue(new Uri("/index.htm")));
        $request->expects($this->atLeastOnce())
            ->method("getHeader")
            ->with("If-Match")
            ->will($this->returnValue("any value"));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"));

        $promise = $root->respond($request);
        /** @var \Aerys\Response $response */
        $response = Promise\wait($promise);

        $this->assertSame(Status::PRECONDITION_FAILED, $response->getStatus());
    }

    public function testPreconditionNotModified() {
        $root = new Root(self::fixturePath());
        $root->setOption("useEtagInode", false);
        $diskPath = realpath(self::fixturePath())."/index.htm";
        $etag = md5($diskPath.filemtime($diskPath).filesize($diskPath));
        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue(new Uri("/index.htm")));
        $request->expects($this->any())
            ->method("getHeader")
            ->will($this->returnCallback(function ($header) use ($etag) {
                return [
                "If-Match" => $etag,
                "If-Modified-Since" => "2.1.1970",
            ][$header] ?? "";
            }));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"));

        $promise = $root->respond($request);
        /** @var \Aerys\Response $response */
        $response = Promise\wait($promise);

        $this->assertSame(Status::NOT_MODIFIED, $response->getStatus());
        $this->assertSame(gmdate("D, d M Y H:i:s", filemtime($diskPath))." GMT", $response->getHeader("last-modified"));
        $this->assertSame($etag, $response->getHeader("etag"));
    }

    public function testPreconditionRangeFail() {
        $root = new Root(self::fixturePath());
        $root->setOption("useEtagInode", false);
        $diskPath = realpath(self::fixturePath())."/index.htm";
        $etag = md5($diskPath.filemtime($diskPath).filesize($diskPath));
        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue(new Uri("/index.htm")));
        $request->expects($this->any())
            ->method("getHeader")
            ->will($this->returnCallback(function ($header) use ($etag) {
                return [
                "If-Range" => "foo",
            ][$header] ?? "";
            }));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"));

        $promise = $root->respond($request);
        /** @var \Aerys\Response $response */
        $response = Promise\wait($promise);

        $stream = $response->getBody();
        $this->assertSame("test", Promise\wait($stream->read()));
    }

    public function testBadRange() {
        $root = new Root(self::fixturePath());

        $server = $this->createMock(Server::class);
        $server->method('getOptions')
            ->willReturn((new Options)->withDebugMode(true));

        $root->onStart($server, $this->createMock(Logger::class), new DefaultErrorHandler);

        $root->setOption("useEtagInode", false);
        $diskPath = realpath(self::fixturePath())."/index.htm";
        $etag = md5($diskPath.filemtime($diskPath).filesize($diskPath));
        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue(new Uri("/index.htm")));
        $request->expects($this->any())
            ->method("getHeader")
            ->will($this->returnCallback(function ($header) use ($etag) {
                return [
                "If-Range" => $etag,
                "Range" => "bytes=7-10",
            ][$header] ?? "";
            }));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"));

        $promise = $root->respond($request);
        /** @var \Aerys\Response $response */
        $response = Promise\wait($promise);

        $this->assertSame(Status::RANGE_NOT_SATISFIABLE, $response->getStatus());
        $this->assertSame("*/4", $response->getHeader("content-range"));
    }

    /**
     * @dataProvider provideValidRanges
     */
    public function testValidRange(string $range, callable $validator) {
        Loop::run(function () use ($range, $validator) {
            $root = new Root(self::fixturePath());
            $root->setOption("useEtagInode", false);
            $request = $this->createMock(Request::class);
            $request->expects($this->once())
                ->method("getUri")
                ->will($this->returnValue(new Uri("/index.htm")));
            $request->expects($this->any())
                ->method("getHeader")
                ->will($this->returnCallback(function ($header) use ($range) {
                    return [
                        "If-Range" => "+1 second",
                        "Range"    => "bytes=$range",
                    ][$header] ?? "";
                }));
            $request->expects($this->any())
                ->method("getMethod")
                ->will($this->returnValue("GET"));

            /** @var \Aerys\Response $response */
            $response = yield $root->respond($request);

            $this->assertSame(Status::PARTIAL_CONTENT, $response->getStatus());

            $body = "";
            while (null !== $chunk = yield $response->getBody()->read()) {
                $body .= $chunk;
            }

            $validator($response->getHeaders(), $body);

            Loop::stop();
        });
    }

    public function provideValidRanges() {
        return [
            ["1-2", function ($headers, $body) {
                $this->assertEquals(2, $headers["content-length"][0]);
                $this->assertEquals("bytes 1-2/4", $headers["content-range"][0]);
                $this->assertEquals("es", $body);
            }],
            ["-0,1-2,2-", function ($headers, $body) {
                $start = "multipart/byteranges; boundary=";
                $this->assertEquals($start, substr($headers["content-type"][0], 0, strlen($start)));
                $boundary = substr($headers["content-type"][0], strlen($start));
                foreach ([["3-3", "t"], ["1-2", "es"], ["2-3", "st"]] as list($range, $text)) {
                    $expected = <<<PART
--$boundary\r
Content-Type: text/plain; charset=utf-8\r
Content-Range: bytes $range/4\r
\r
$text\r

PART;
                    $this->assertEquals($expected, substr($body, 0, strlen($expected)));
                    $body = substr($body, strlen($expected));
                }
                $this->assertEquals("--$boundary--", $body);
            }],
        ];
    }

    /**
     * @depends testBasicFileResponse
     */
    public function testMimetypeParsing(Root $root) {
        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue(new Uri("/svg.svg")));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"));

        $promise = $root->respond($request);
        /** @var \Aerys\Response $response */
        $response = Promise\wait($promise);

        $this->assertSame("image/svg+xml", $response->getHeader("content-type"));
        $stream = $response->getBody();
        $this->assertSame("<svg></svg>", Promise\wait($stream->read()));
    }
}

<?php

namespace Aerys\Test\Handlers\Static;

use Aerys\Responders\Documents\DocRoot,
    Alert\NativeReactor,
    Aerys\Status,
    Aerys\Server,
    org\bovigo\vfs\vfsStream;

/**
 * vfsStream can't resolve realpath() correctly so we mock
 * this functionality for most of our tests.
 */
class VfsRealpathHandler extends DocRoot {
    protected function validateFilePath($filePath) {
        return $filePath;
    }
}

class DocRootTest extends \PHPUnit_Framework_TestCase {

    private static $root;

    static function setUpBeforeClass() {
        self::$root = vfsStream::setup('root');
        $path = FIXTURE_DIR . '/vfs/static_handler_root';
        vfsStream::copyFromFileSystem($path, self::$root);
    }

    static function tearDownAfterClass() {
        self::$root = NULL;
    }

    private function parseHeadersIntoMap($headersArr) {
        $results = [];
        foreach ($headersArr as $header) {
            $colonPos = strpos($header, ':');
            $field = substr($header, 0, $colonPos);
            $value = ltrim(substr($header, $colonPos + 1), ' ');

            $results[$field] = $value;
        }

        return $results;
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    function testSetOptionThrowsOnInvalidDocRoot() {
        $reactor = $this->getMock('Alert\Reactor');
        $handler = new VfsRealpathHandler($reactor);
        $handler->setAllOptions([
            'docroot' => 'vfs://dirthatdoesntexist'
        ]);
    }
    
    function testClearCache() {
        $this->assertTrue(file_exists('vfs://root/index.html'));

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/'
        ];

        $reactor = new NativeReactor;
        $handler = new VfsRealpathHandler($reactor);
        $handler->setAllOptions([
            'docroot' => 'vfs://root',
        ]);

        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $firstBody = $asgiResponse[3];
        
        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $secondBody = $asgiResponse[3];
        
        $this->assertSame($firstBody, $secondBody);
        
        $handler->clearCache();
        
        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $thirdBody = $asgiResponse[3];
        
        $this->assertSame($thirdBody, $secondBody);
    }

    function testSetEtagMode() {
        $reactor = $this->getMock('Alert\Reactor');
        $handler = new VfsRealpathHandler($reactor);
        $handler->setAllOptions([
            'docroot' => 'vfs://root',
            'eTagMode' => DocRoot::ETAG_NONE
        ]);

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/'
        ];

        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);

        $this->assertFalse(isset($headers['ETag']));
        $this->assertEquals(200, $asgiResponse[0]);

    }

    function testSetCacheTtl() {
        $reactor = $this->getMock('Alert\Reactor');
        $handler = new VfsRealpathHandler($reactor);
        $handler->setAllOptions([
            'docroot' => 'vfs://root',
            'cacheTtl' => 30
        ]);
    }

    function testSetIndexes() {
        $this->assertTrue(file_exists('vfs://root/index.html'));

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/'
        ];

        $reactor = $this->getMock('Alert\Reactor');
        $handler = new VfsRealpathHandler($reactor);
        $handler->setAllOptions([
            'docroot' => 'vfs://root'
        ]);

        // Should match /index.html as per the default index settings
        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $this->assertEquals(200, $asgiResponse[0]);

        // Set an empty index array
        $handler->setAllOptions([
            'indexes' => []
        ]);

        // Should 404 because we removed directory index file matching
        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $this->assertEquals(404, $asgiResponse[0]);
    }

    function testSetIndexRedirection() {
        $this->assertTrue(file_exists('vfs://root/index.html'));

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/index.html'
        ];

        $reactor = $this->getMock('Alert\Reactor');
        $handler = new VfsRealpathHandler($reactor);
        $handler->setAllOptions([
            'docroot' => 'vfs://root',
        ]);

        // Index should be redirected by default
        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $this->assertEquals(301, $asgiResponse[0]);

        // Turn off auto-redirection
        $handler->setAllOptions([
            'indexRedirection' => FALSE,
        ]);

        // Should 200 because we turned off auto-redirection of index files
        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $this->assertEquals(200, $asgiResponse[0]);
    }

    function testSetExpiresHeaderPeriod() {
        $this->assertTrue(file_exists('vfs://root/index.html'));

        $reactor = $this->getMock('Alert\Reactor');
        $handler = new VfsRealpathHandler($reactor);
        $handler->setAllOptions([
            'docroot' => 'vfs://root',
        ]);

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/'
        ];

        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);
        
        $expires = strtotime($headers['Expires']);

        $this->assertEquals(300, $expires - time());

        $handler->setAllOptions([
            'expiresHeaderPeriod' => -1,
        ]);

        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);
        $this->assertSame('0', $headers['Expires']);
    }

    function testSetCustomMimeTypes() {
        $this->assertTrue(file_exists('vfs://root/test.txt'));

        $reactor = $this->getMock('Alert\Reactor');
        $handler = new VfsRealpathHandler($reactor);
        $handler->setAllOptions([
            'docroot' => 'vfs://root',
        ]);

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test.txt'
        ];

        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);

        $this->assertEquals('text/plain; charset=utf-8', $headers['Content-Type']);

        $handler->setOption('customMimeTypes', [
            'txt' => 'text/awesome'
        ]);

        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);

        $this->assertEquals('text/awesome; charset=utf-8', $headers['Content-Type']);
    }

    function testSetDefaultTextCharset() {
        $this->assertTrue(file_exists('vfs://root/test.txt'));

        $reactor = $this->getMock('Alert\Reactor');
        $handler = new VfsRealpathHandler($reactor);
        $handler->setAllOptions([
            'docroot' => 'vfs://root',
        ]);

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test.txt'
        ];

        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);

        $this->assertEquals('text/plain; charset=utf-8', $headers['Content-Type']);

        $handler->setOption('defaultTextCharset', 'iso-8859-1');

        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);

        $this->assertEquals('text/plain; charset=iso-8859-1', $headers['Content-Type']);
    }

    function provideAsgiEnvRequests() {
        $return = [];

        // 0 ---------------------------------------------------------------------------------------

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/'
        ];
        $expectedCode = 200;

        $return[] = [$asgiRequest, $expectedCode];

        // 1 ---------------------------------------------------------------------------------------

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/empty_dir/'
        ];
        $expectedCode = 404;

        $return[] = [$asgiRequest, $expectedCode];

        // 2 ---------------------------------------------------------------------------------------

        $asgiRequest = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI_PATH' => '/'
        ];
        $expectedCode = 405;

        $return[] = [$asgiRequest, $expectedCode];

        // 3 ---------------------------------------------------------------------------------------

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/index.html' // index files are auto-redirected to / by default
        ];
        $expectedCode = 301;

        $return[] = [$asgiRequest, $expectedCode];

        // x ---------------------------------------------------------------------------------------

        return $return;
    }

    /**
     * @dataProvider provideAsgiEnvRequests
     */
    function testRequest($asgiRequest, $expectedCode) {
        $reactor = $this->getMock('Alert\Reactor');
        $handler = new VfsRealpathHandler($reactor);
        $handler->setAllOptions([
            'docroot' => 'vfs://root',
        ]);

        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $statusCode = $asgiResponse[0];

        $this->assertEquals($expectedCode, $statusCode);
    }

    function testOptionsRequest() {
        $reactor = $this->getMock('Alert\Reactor');
        $handler = new VfsRealpathHandler($reactor);
        $handler->setAllOptions([
            'docroot' => 'vfs://root',
        ]);

        $asgiRequest = [
            'REQUEST_METHOD' => 'OPTIONS',
            'REQUEST_URI_PATH' => '/'
        ];

        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $status = $asgiResponse[0];
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);

        $this->assertEquals(200, $status);
        $this->assertEquals('GET, HEAD, OPTIONS', $headers['Allow']);
    }


    function provideRangeRequests() {
        $return = [];

        // 0 ---------------------------------------------------------------------------------------

        // IMPORTANT: ASSUMES RESOURCE test.txt == 42 BYTES IN SIZE

        $startPos = 0;
        $endPos = 5;
        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test.txt',
            'HTTP_RANGE' => "bytes={$startPos}-{$endPos}"
        ];
        $expectedStatus = Status::PARTIAL_CONTENT;

        $return[] = [$asgiRequest, $expectedStatus, $startPos, $endPos];

        // 1 ---------------------------------------------------------------------------------------

        // IMPORTANT: ASSUMES RESOURCE test.txt == 42 BYTES IN SIZE

        $startPos = 0;
        $endPos = 20;
        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test.txt',
            'HTTP_RANGE' => "bytes={$startPos}-{$endPos}"
        ];
        $expectedStatus = Status::PARTIAL_CONTENT;

        $return[] = [$asgiRequest, $expectedStatus, $expectedStartPos = 0, $expectedEndPos = 20];

        // 2 ---------------------------------------------------------------------------------------

        // IMPORTANT: ASSUMES RESOURCE test.txt == 42 BYTES IN SIZE

        $startPos = 10;
        $endPos = 41;
        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test.txt',
            'HTTP_RANGE' => "bytes={$startPos}-{$endPos}"
        ];
        $expectedStatus = Status::PARTIAL_CONTENT;

        $return[] = [$asgiRequest, $expectedStatus, $expectedStartPos = 10, $expectedEndPos = 41];

        // 3 ---------------------------------------------------------------------------------------

        // IMPORTANT: ASSUMES RESOURCE test.txt == 42 BYTES IN SIZE

        $startPos = '';
        $endPos = 20;
        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test.txt',
            'HTTP_RANGE' => "bytes={$startPos}-{$endPos}"
        ];
        $expectedStatus = Status::PARTIAL_CONTENT;

        $return[] = [$asgiRequest, $expectedStatus, $expectedStartPos = 21, $expectedEndPos = 41];

        // 4 ---------------------------------------------------------------------------------------

        // IMPORTANT: ASSUMES RESOURCE test.txt == 42 BYTES IN SIZE

        $startPos = 20;
        $endPos = '';
        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test.txt',
            'HTTP_RANGE' => "bytes={$startPos}-{$endPos}"
        ];
        $expectedStatus = Status::PARTIAL_CONTENT;

        $return[] = [$asgiRequest, $expectedStatus, $expectedStartPos = 20, $expectedEndPos = 41];

        // x ---------------------------------------------------------------------------------------

        return $return;
    }

    /**
     * @dataProvider provideRangeRequests
     */
    function testRangeRequest($asgiRequest, $expectedStatus, $expectedStartPos, $expectedEndPos) {
        $reactor = $this->getMock('Alert\Reactor');
        $handler = new VfsRealpathHandler($reactor);
        $handler->setAllOptions([
            'docroot' => 'vfs://root',
        ]);

        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        list($status, $reason, $headers, $body) = $asgiResponse;

        $this->assertEquals($expectedStatus, $status);
        $this->assertEquals($expectedStartPos, $body->getStartPosition());
        $this->assertEquals($expectedEndPos, $body->getEndPosition());
    }

    function provideUnsatisfiableRangeRequests() {
        $return = [];

        // 0 ---------------------------------------------------------------------------------------

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test.txt',
            'HTTP_RANGE' => "bytes=-"
        ];
        $return[] = [$asgiRequest];

        // 1 ---------------------------------------------------------------------------------------

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test.txt',
            'HTTP_RANGE' => "bytes="
        ];
        $return[] = [$asgiRequest];

        // 2 ---------------------------------------------------------------------------------------

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test.txt',
            'HTTP_RANGE' => 'bytes=8888888-9999999'
        ];
        $return[] = [$asgiRequest];

        // 3 ---------------------------------------------------------------------------------------

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test.txt',
            'HTTP_RANGE' => ['bytes=8888888-9999999', 'bytes=6666666-7777777']
        ];
        $return[] = [$asgiRequest];

        // x ---------------------------------------------------------------------------------------

        return $return;
    }

    /**
     * @dataProvider provideUnsatisfiableRangeRequests
     */
    function testNotSatisfiableReturnedOnBadRangeRequest($asgiRequest) {
        $reactor = $this->getMock('Alert\Reactor');
        $handler = new VfsRealpathHandler($reactor);
        $handler->setAllOptions([
            'docroot' => 'vfs://root',
        ]);

        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);

        $this->assertEquals(Status::REQUESTED_RANGE_NOT_SATISFIABLE, $asgiResponse[0]);
    }

    function testMultipartRangeRequest() {
        $reactor = $this->getMock('Alert\Reactor');
        $handler = new VfsRealpathHandler($reactor);
        $handler->setAllOptions([
            'docroot' => 'vfs://root',
        ]);

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test.txt',
            'HTTP_RANGE' => ['bytes=0-10', 'bytes=11-20']
        ];

        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        list($status, $reason, $headers, $body) = $asgiResponse;

        $this->assertEquals(Status::PARTIAL_CONTENT, $status);
        $this->assertInstanceOf('Aerys\Writing\MultiPartByteRangeBody', $body);

    }

    function testDefaultMimeTypeReturnedIfUnkownOrMissingExtension() {
        $this->assertTrue(file_exists('vfs://root/no_extension'));

        $reactor = $this->getMock('Alert\Reactor');
        $handler = new VfsRealpathHandler($reactor);
        $handler->setAllOptions([
            'docroot' => 'vfs://root',
        ]);

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/no_extension'
        ];

        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);

        $this->assertEquals('text/plain; charset=utf-8', $headers['Content-Type']);

        $handler->setOption('DefaultMimeType', 'application/octet-stream');

        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);

        $this->assertEquals('application/octet-stream', $headers['Content-Type']);

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/file.unknowntype'
        ];

        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);

        $this->assertEquals('application/octet-stream', $headers['Content-Type']);
    }

    function testNotModifiedOnConditionalGetUsingIfNoneMatch() {
        $reactor = $this->getMock('Alert\Reactor');
        $handler = new VfsRealpathHandler($reactor);
        $handler->setAllOptions([
            'docroot' => 'vfs://root',
        ]);

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/'
        ];
        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);
        $eTag = $headers['ETag'];

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/',
            'HTTP_IF_NONE_MATCH' => $eTag
        ];

        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $statusCode = $asgiResponse[0];

        $this->assertEquals(Status::NOT_MODIFIED, $statusCode);
    }

    function testPreconditionFailedResponseIfETagNotMatched() {
        $reactor = $this->getMock('Alert\Reactor');
        $handler = new VfsRealpathHandler($reactor);
        $handler->setAllOptions([
            'docroot' => 'vfs://root',
        ]);

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/',
            'HTTP_IF_MATCH' => 'ZANZIBAR'
        ];
        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $statusCode = $asgiResponse[0];

        $this->assertEquals(Status::PRECONDITION_FAILED, $statusCode);
    }

    function testNotModifiedResponseOnIfModifiedSinceFailure() {
        $reactor = $this->getMock('Alert\Reactor');
        $handler = new VfsRealpathHandler($reactor);
        $handler->setAllOptions([
            'docroot' => 'vfs://root',
        ]);

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/'
        ];
        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);
        $lastModified = $headers['Last-Modified'];

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/',
            'HTTP_IF_MODIFIED_SINCE' => $lastModified
        ];

        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $statusCode = $asgiResponse[0];

        $this->assertEquals(Status::NOT_MODIFIED, $statusCode);
    }

    function testPreconditionFailedResponseOnIfUnmodifiedSinceFailure() {
        $reactor = $this->getMock('Alert\Reactor');
        $handler = new VfsRealpathHandler($reactor);
        $handler->setAllOptions([
            'docroot' => 'vfs://root',
        ]);

        $ifUnmodifiedSince = gmdate('D, d M Y H:i:s', 1);

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/',
            'HTTP_IF_UNMODIFIED_SINCE' => $ifUnmodifiedSince
        ];
        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $statusCode = $asgiResponse[0];

        $this->assertEquals(Status::PRECONDITION_FAILED, $statusCode);
    }

    function testIfRangeMatchesETagResponse() {
        $reactor = $this->getMock('Alert\Reactor');
        $handler = new VfsRealpathHandler($reactor);
        $handler->setAllOptions([
            'docroot' => 'vfs://root',
        ]);

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test.txt'
        ];

        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);
        $eTag = $headers['ETag'];

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test.txt',
            'HTTP_IF_RANGE' => $eTag,
            'HTTP_RANGE' => 'bytes=0-5'
        ];
        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $statusCode = $asgiResponse[0];

        $this->assertEquals(Status::PARTIAL_CONTENT, $statusCode);
    }

    function testIfRangeMatchesLastModifiedResponse() {
        $reactor = $this->getMock('Alert\Reactor');
        $handler = new VfsRealpathHandler($reactor);
        $handler->setAllOptions([
            'docroot' => 'vfs://root',
        ]);

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test.txt'
        ];

        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);
        $lastModified = $headers['Last-Modified'];

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test.txt',
            'HTTP_IF_RANGE' => $lastModified,
            'HTTP_RANGE' => 'bytes=0-5'
        ];
        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $statusCode = $asgiResponse[0];

        $this->assertEquals(Status::PARTIAL_CONTENT, $statusCode);
    }

    function testFullBodyResponseOnIfRangeFailure() {
        $reactor = $this->getMock('Alert\Reactor');
        $handler = new VfsRealpathHandler($reactor);
        $handler->setAllOptions([
            'docroot' => 'vfs://root',
        ]);

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test.txt'
        ];

        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);
        $lastModified = $headers['Last-Modified'];

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => '/test.txt',
            'HTTP_IF_RANGE' => 'ZANZIBAR',
            'HTTP_RANGE' => 'bytes=0-5'
        ];
        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $statusCode = $asgiResponse[0];

        $this->assertEquals(Status::OK, $statusCode);
    }

    /**
     * @dataProvider provideDangerousRelativeRequestUris
     */
    function testRelativePathCantAscendPastDocumentRoot($requestUri) {
        $reactor = $this->getMock('Alert\Reactor');
        $handler = new DocRoot($reactor);
        $handler->setAllOptions([
            'docroot' => FIXTURE_DIR . '/vfs/static_handler_root',
        ]);

        $asgiRequest = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI_PATH' => $requestUri
        ];
        $expectedCode = 404;

        $asgiResponse = $handler->__invoke($asgiRequest, $requestId = 42);
        $statusCode = $asgiResponse[0];

        $this->assertEquals($expectedCode, $statusCode);
    }

    function provideDangerousRelativeRequestUris() {
        return [
            ['/../test.txt'],
            ['/../'],
            ['/../empty_dir/'],
            ['/../static_handler_root2/']
        ];
    }
}

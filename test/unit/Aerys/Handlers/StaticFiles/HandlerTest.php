<?php

use Aerys\Handlers\StaticFiles\Handler,
    Aerys\Status,
    Aerys\Server,
    org\bovigo\vfs\vfsStream;

class HandlerTest extends PHPUnit_Framework_TestCase {
    
    private static $root;
    
    static function setUpBeforeClass() {
        self::$root = vfsStream::setup('root');
        $path = FIXTURE_DIR . '/vfs/static_handler_root';
        vfsStream::copyFromFileSystem($path, self::$root);
    }
    
    static function tearDownAfterClass() {
        self::$root = NULL;
    }
    
    /**
     * @expectedException InvalidArgumentException
     */
    function testConstructThrowsExceptionOnInvalidDocRoot() {
        $handler = new VfsRealpathHandler('vfs://dirthatdoesntexist');
    }
    
    function testSetFileDescriptorCacheTtl() {
        $handler = new VfsRealpathHandler('vfs://root');
        $handler->setFileDescriptorCacheTtl(30);
    }
    
    function testSetIndexes() {
        $this->assertTrue(file_exists('vfs://root/index.html'));
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/'
        ];
        
        $handler = new VfsRealpathHandler('vfs://root');
        
        // Should match /index.html as per the default index settings
        $asgiResponse = $handler->__invoke($asgiEnv);
        $this->assertEquals(200, $asgiResponse[0]);
        
        // Set an empty index array
        $noIndexes = [];
        $handler->setIndexes($noIndexes);
        
        // Should 404 because we removed directory index file matching
        $asgiResponse = $handler->__invoke($asgiEnv);
        $this->assertEquals(404, $asgiResponse[0]);
    }
    
    function testSetIndexRedirection() {
        $this->assertTrue(file_exists('vfs://root/index.html'));
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/index.html'
        ];
        
        $handler = new VfsRealpathHandler('vfs://root');
        
        // Index should be redirected by default
        $asgiResponse = $handler->__invoke($asgiEnv);
        $this->assertEquals(301, $asgiResponse[0]);
        
        // Turn off auto-redirection
        $handler->setIndexRedirection(FALSE);
        
        // Should 200 because we turned off auto-redirection of index files
        $asgiResponse = $handler->__invoke($asgiEnv);
        $this->assertEquals(200, $asgiResponse[0]);
    }
    
    function testSetExpiresHeaderPeriod() {
        $this->assertTrue(file_exists('vfs://root/index.html'));
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/'
        ];
        
        $handler = new VfsRealpathHandler('vfs://root');
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $headers = $asgiResponse[2];
        $date = strtotime($headers['Date']);
        $expires = strtotime($headers['Expires']);
        
        $this->assertEquals(300, $expires - $date);
        
        $handler->setExpiresHeaderPeriod(-1);
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $headers = $asgiResponse[2];
        $this->assertEquals($headers['Date'], $headers['Expires']);
    }
    
    function testSetCustomMimeTypes() {
        $this->assertTrue(file_exists('vfs://root/test.txt'));
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test.txt'
        ];
        
        $handler = new VfsRealpathHandler('vfs://root');
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $headers = $asgiResponse[2];
        
        $this->assertEquals('text/plain; charset=utf-8', $headers['Content-Type']);
        
        $handler->setCustomMimeTypes([
            'txt' => 'text/awesome'
        ]);
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $headers = $asgiResponse[2];
        
        $this->assertEquals('text/awesome; charset=utf-8', $headers['Content-Type']);
    }
    
    function testSetDefaultTextCharset() {
        $this->assertTrue(file_exists('vfs://root/test.txt'));
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test.txt'
        ];
        
        $handler = new VfsRealpathHandler('vfs://root');
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $headers = $asgiResponse[2];
        
        $this->assertEquals('text/plain; charset=utf-8', $headers['Content-Type']);
        
        $handler->setDefaultTextCharset('iso-8859-1');
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $headers = $asgiResponse[2];
        
        $this->assertEquals('text/plain; charset=iso-8859-1', $headers['Content-Type']);
    }
    
    function provideAsgiEnvRequests() {
        $return = [];
        
        // 0 ---------------------------------------------------------------------------------------
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/'
        ];
        $expectedCode = 200;
        
        $return[] = [$asgiEnv, $expectedCode];
        
        // 1 ---------------------------------------------------------------------------------------
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/empty_dir/'
        ];
        $expectedCode = 404;
        
        $return[] = [$asgiEnv, $expectedCode];
        
        // 2 ---------------------------------------------------------------------------------------
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/'
        ];
        $expectedCode = 405;
        
        $return[] = [$asgiEnv, $expectedCode];
        
        // 3 ---------------------------------------------------------------------------------------
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/index.html?query=1' // index files are auto-redirected to / by default
        ];
        $expectedCode = 301;
        
        $return[] = [$asgiEnv, $expectedCode];
        
        // x ---------------------------------------------------------------------------------------
        
        return $return;
    }
    
    /**
     * @dataProvider provideAsgiEnvRequests
     */
    function testRequest($asgiEnv, $expectedCode) {
        $handler = new VfsRealpathHandler('vfs://root');
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $statusCode = $asgiResponse[0];
        
        $this->assertEquals($expectedCode, $statusCode);
    }
    
    function testOptionsRequest() {
        $handler = new VfsRealpathHandler('vfs://root');
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'OPTIONS',
            'REQUEST_URI' => '/'
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        list($status, $reason, $headers, $body) = $asgiResponse;
        
        $this->assertEquals(200, $status);
        $this->assertEquals('GET, HEAD, OPTIONS', $headers['Allow']);
    }
    
    function testRangeRequest() {
        $handler = new VfsRealpathHandler('vfs://root');
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test.txt',
            'HTTP_RANGE' => 'bytes=0-5'
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        list($status, $reason, $headers, $body) = $asgiResponse;
        
        $this->assertEquals(206, $status);
        $this->assertInstanceOf('Aerys\Io\ByteRangeBody', $body);
        $this->assertEquals(0, $body->getStartPos());
        $this->assertEquals(5, $body->getEndPos());
    }
    
    function testDefaultMimeTypeReturnedIfUnkownOrMissingExtension() {
        $this->assertTrue(file_exists('vfs://root/no_extension'));
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/no_extension'
        ];
        
        $handler = new VfsRealpathHandler('vfs://root');
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $headers = $asgiResponse[2];
        
        $this->assertEquals('text/plain; charset=utf-8', $headers['Content-Type']);
        
        $handler->setDefaultMimeType('application/octet-stream');
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $headers = $asgiResponse[2];
        
        $this->assertEquals('application/octet-stream', $headers['Content-Type']);
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/file.unknowntype'
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $headers = $asgiResponse[2];
        
        $this->assertEquals('application/octet-stream', $headers['Content-Type']);
    }
    
    function testNotModifiedOnConditionalGetUsingIfNoneMatch() {
        $handler = new VfsRealpathHandler('vfs://root');
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/'
        ];
        $asgiResponse = $handler->__invoke($asgiEnv);
        $eTag = $asgiResponse[2]['ETag'];
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_IF_NONE_MATCH' => $eTag
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $statusCode = $asgiResponse[0];
        
        $this->assertEquals(Status::NOT_MODIFIED, $statusCode);
    }
    
    function testPreconditionFailedResponseIfETagNotMatched() {
        $handler = new VfsRealpathHandler('vfs://root');
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_IF_MATCH' => 'ZANZIBAR'
        ];
        $asgiResponse = $handler->__invoke($asgiEnv);
        $statusCode = $asgiResponse[0];
        
        $this->assertEquals(Status::PRECONDITION_FAILED, $statusCode);
    }
    
    function testNotModifiedResponseOnIfModifiedSinceFailure() {
        $handler = new VfsRealpathHandler('vfs://root');
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/'
        ];
        $asgiResponse = $handler->__invoke($asgiEnv);
        $lastModified = $asgiResponse[2]['Last-Modified'];
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_IF_MODIFIED_SINCE' => $lastModified
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $statusCode = $asgiResponse[0];
        
        $this->assertEquals(Status::NOT_MODIFIED, $statusCode);
    }
    
    function testPreconditionFailedResponseOnIfUnmodifiedSinceFailure() {
        $handler = new VfsRealpathHandler('vfs://root');
        
        $ifUnmodifiedSince = date(Server::HTTP_DATE, 1);
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_IF_UNMODIFIED_SINCE' => $ifUnmodifiedSince
        ];
        $asgiResponse = $handler->__invoke($asgiEnv);
        $statusCode = $asgiResponse[0];
        
        $this->assertEquals(Status::PRECONDITION_FAILED, $statusCode);
    }
    
    function testRequestRangeNotSatisfiableResponse() {
        $handler = new VfsRealpathHandler('vfs://root');
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test.txt',
            'HTTP_RANGE' => 'bytes=5555555-9999999'
        ];
        $asgiResponse = $handler->__invoke($asgiEnv);
        $statusCode = $asgiResponse[0];
        
        $this->assertEquals(Status::REQUESTED_RANGE_NOT_SATISFIABLE, $statusCode);
    }
    
    function testIfRangeMatchesETagResponse() {
        $handler = new VfsRealpathHandler('vfs://root');
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test.txt'
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $eTag = $asgiResponse[2]['ETag'];
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test.txt',
            'HTTP_IF_RANGE' => $eTag,
            'HTTP_RANGE' => 'bytes=0-5'
        ];
        $asgiResponse = $handler->__invoke($asgiEnv);
        $statusCode = $asgiResponse[0];
        
        $this->assertEquals(Status::PARTIAL_CONTENT, $statusCode);
    }
    
    function testIfRangeMatchesLastModifiedResponse() {
        $handler = new VfsRealpathHandler('vfs://root');
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test.txt'
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $lastModified = $asgiResponse[2]['Last-Modified'];
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test.txt',
            'HTTP_IF_RANGE' => $lastModified,
            'HTTP_RANGE' => 'bytes=0-5'
        ];
        $asgiResponse = $handler->__invoke($asgiEnv);
        $statusCode = $asgiResponse[0];
        
        $this->assertEquals(Status::PARTIAL_CONTENT, $statusCode);
    }
    
    function testFullBodyResponseOnIfRangeFailure() {
        $handler = new VfsRealpathHandler('vfs://root');
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test.txt'
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $lastModified = $asgiResponse[2]['Last-Modified'];
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test.txt',
            'HTTP_IF_RANGE' => 'ZANZIBAR',
            'HTTP_RANGE' => 'bytes=0-5'
        ];
        $asgiResponse = $handler->__invoke($asgiEnv);
        $statusCode = $asgiResponse[0];
        
        $this->assertEquals(Status::OK, $statusCode);
    }
}

class VfsRealpathHandler extends Handler {
    protected function validateFilePath($filePath) {
        return $filePath;
    }
}





























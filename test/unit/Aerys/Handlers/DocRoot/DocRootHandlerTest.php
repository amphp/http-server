<?php

use Aerys\Handlers\DocRoot\DocRootHandler,
    Alert\ReactorFactory,
    Aerys\Status,
    Aerys\Server,
    org\bovigo\vfs\vfsStream;

class DocRootHandlerTest extends PHPUnit_Framework_TestCase {
    
    const HEADERS_PATTERN = "/
        (?P<field>[^\(\)<>@,;:\\\"\/\[\]\?\={}\x20\x09\x01-\x1F\x7F]+):[\x20\x09]*
        (?P<value>[^\x01-\x08\x0A-\x1F\x7F]*)[\x0D]?[\x20\x09]*[\r]?[\n]
    /x";
    
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
     * @expectedException InvalidArgumentException
     */
    function testConstructThrowsExceptionOnInvalidDocRoot() {
        $handler = new VfsRealpathHandler('vfs://dirthatdoesntexist');
    }
    
    function testSetEtagMode() {
        $handler = new VfsRealpathHandler('vfs://root');
        $handler->setETagMode(DocRootHandler::ETAG_NONE);
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/'
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $headers = $asgiResponse[2];
        $this->assertFalse(isset($headers['ETag']));
        
        $this->assertEquals(200, $asgiResponse[0]);
        
    }
    
    function testSetCacheTtl() {
        $handler = new VfsRealpathHandler('vfs://root');
        $handler->setCacheTtl(30);
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
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);
        
        $date = strtotime($headers['Date']);
        $expires = strtotime($headers['Expires']);
        
        $this->assertEquals(300, $expires - $date);
        
        $handler->setExpiresHeaderPeriod(-1);
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);
        $this->assertSame('0', $headers['Expires']);
    }
    
    function testSetCustomMimeTypes() {
        $this->assertTrue(file_exists('vfs://root/test.txt'));
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test.txt'
        ];
        
        $handler = new VfsRealpathHandler('vfs://root');
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);
        
        $this->assertEquals('text/plain; charset=utf-8', $headers['Content-Type']);
        
        $handler->setCustomMimeTypes([
            'txt' => 'text/awesome'
        ]);
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);
        
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
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);
        
        $this->assertEquals('text/plain; charset=utf-8', $headers['Content-Type']);
        
        $handler->setDefaultTextCharset('iso-8859-1');
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);
        
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
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test.txt',
            'HTTP_RANGE' => "bytes={$startPos}-{$endPos}"
        ];
        $expectedStatus = Status::PARTIAL_CONTENT;
        
        $return[] = [$asgiEnv, $expectedStatus, $startPos, $endPos];
        
        // 1 ---------------------------------------------------------------------------------------
        
        // IMPORTANT: ASSUMES RESOURCE test.txt == 42 BYTES IN SIZE
        
        $startPos = 0;
        $endPos = 20;
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test.txt',
            'HTTP_RANGE' => "bytes={$startPos}-{$endPos}"
        ];
        $expectedStatus = Status::PARTIAL_CONTENT;
        
        $return[] = [$asgiEnv, $expectedStatus, $expectedStartPos = 0, $expectedEndPos = 20];
        
        // 2 ---------------------------------------------------------------------------------------
        
        // IMPORTANT: ASSUMES RESOURCE test.txt == 42 BYTES IN SIZE
        
        $startPos = 10;
        $endPos = 41;
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test.txt',
            'HTTP_RANGE' => "bytes={$startPos}-{$endPos}"
        ];
        $expectedStatus = Status::PARTIAL_CONTENT;
        
        $return[] = [$asgiEnv, $expectedStatus, $expectedStartPos = 10, $expectedEndPos = 41];
        
        // 3 ---------------------------------------------------------------------------------------
        
        // IMPORTANT: ASSUMES RESOURCE test.txt == 42 BYTES IN SIZE
        
        $startPos = '';
        $endPos = 20;
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test.txt',
            'HTTP_RANGE' => "bytes={$startPos}-{$endPos}"
        ];
        $expectedStatus = Status::PARTIAL_CONTENT;
        
        $return[] = [$asgiEnv, $expectedStatus, $expectedStartPos = 21, $expectedEndPos = 41];
        
        // 4 ---------------------------------------------------------------------------------------
        
        // IMPORTANT: ASSUMES RESOURCE test.txt == 42 BYTES IN SIZE
        
        $startPos = 20;
        $endPos = '';
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test.txt',
            'HTTP_RANGE' => "bytes={$startPos}-{$endPos}"
        ];
        $expectedStatus = Status::PARTIAL_CONTENT;
        
        $return[] = [$asgiEnv, $expectedStatus, $expectedStartPos = 20, $expectedEndPos = 41];
        
        // x ---------------------------------------------------------------------------------------
        
        return $return;
    }
    
    /**
     * @dataProvider provideRangeRequests
     */
    function testRangeRequest($asgiEnv, $expectedStatus, $expectedStartPos, $expectedEndPos) {
        $handler = new VfsRealpathHandler('vfs://root');
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        list($status, $reason, $headers, $body) = $asgiResponse;
        
        $this->assertEquals($expectedStatus, $status);
        $this->assertEquals($expectedStartPos, $body->getStartPosition());
        $this->assertEquals($expectedEndPos, $body->getEndPosition());
    }
    
    function provideUnsatisfiableRangeRequests() {
        $return = [];
        
        // 0 ---------------------------------------------------------------------------------------
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test.txt',
            'HTTP_RANGE' => "bytes=-"
        ];
        $return[] = [$asgiEnv];
        
        // 1 ---------------------------------------------------------------------------------------
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test.txt',
            'HTTP_RANGE' => "bytes="
        ];
        $return[] = [$asgiEnv];
        
        // 2 ---------------------------------------------------------------------------------------
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test.txt',
            'HTTP_RANGE' => 'bytes=8888888-9999999'
        ];
        $return[] = [$asgiEnv];
        
        // 3 ---------------------------------------------------------------------------------------
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test.txt',
            'HTTP_RANGE' => ['bytes=8888888-9999999', 'bytes=6666666-7777777']
        ];
        $return[] = [$asgiEnv];
        
        // x ---------------------------------------------------------------------------------------
        
        return $return;
    }
    
    /**
     * @dataProvider provideUnsatisfiableRangeRequests
     */
    function testNotSatisfiableReturnedOnBadRangeRequest($asgiEnv) {
        $handler = new VfsRealpathHandler('vfs://root');
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        
        $this->assertEquals(Status::REQUESTED_RANGE_NOT_SATISFIABLE, $asgiResponse[0]);
    }
    
    function testMultipartRangeRequest() {
        $handler = new VfsRealpathHandler('vfs://root');
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test.txt',
            'HTTP_RANGE' => ['bytes=0-10', 'bytes=11-20']
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        list($status, $reason, $headers, $body) = $asgiResponse;
        
        $this->assertEquals(Status::PARTIAL_CONTENT, $status);
        $this->assertInstanceOf('Aerys\Writing\MultiPartByteRangeBody', $body);
        
    }
    
    function testDefaultMimeTypeReturnedIfUnkownOrMissingExtension() {
        $this->assertTrue(file_exists('vfs://root/no_extension'));
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/no_extension'
        ];
        
        $handler = new VfsRealpathHandler('vfs://root');
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);
        
        $this->assertEquals('text/plain; charset=utf-8', $headers['Content-Type']);
        
        $handler->setDefaultMimeType('application/octet-stream');
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);
        
        $this->assertEquals('application/octet-stream', $headers['Content-Type']);
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/file.unknowntype'
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);
        
        $this->assertEquals('application/octet-stream', $headers['Content-Type']);
    }
    
    function testNotModifiedOnConditionalGetUsingIfNoneMatch() {
        $handler = new VfsRealpathHandler('vfs://root');
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/'
        ];
        $asgiResponse = $handler->__invoke($asgiEnv);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);
        $eTag = $headers['ETag'];
        
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
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);
        $lastModified = $headers['Last-Modified'];
        
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
    
    function testIfRangeMatchesETagResponse() {
        $handler = new VfsRealpathHandler('vfs://root');
        
        $asgiEnv = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test.txt'
        ];
        
        $asgiResponse = $handler->__invoke($asgiEnv);
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);
        $eTag = $headers['ETag'];
        
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
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);
        $lastModified = $headers['Last-Modified'];
        
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
        $headers = $this->parseHeadersIntoMap($asgiResponse[2]);
        $lastModified = $headers['Last-Modified'];
        
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

class VfsRealpathHandler extends DocRootHandler {
    function __construct($docRoot) {
        $reactor = (new ReactorFactory)->select();
        parent::__construct($reactor, $docRoot);
    }
    
    protected function validateFilePath($filePath) {
        return $filePath;
    }
}


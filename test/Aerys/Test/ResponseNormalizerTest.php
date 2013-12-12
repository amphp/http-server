<?php

use Aerys\Response, Aerys\ResponseNormalizer;

class ResponseNormalizerTest extends PHPUnit_Framework_TestCase {

    /**
     * @dataProvider provideResponsesWithBadStatusCodes
     * @expectedException \DomainException
     * @expectedExceptionMessage Invalid response status code
     */
    function testNormalizeThrowsOnInvalidStatusCode($response) {
        $normalizer = new ResponseNormalizer;
        $normalizer->normalize($response, $request = [
            'SERVER_PROTOCOL' => '1.1'
        ]);
    }

    function provideResponsesWithBadStatusCodes() {
        return [
            [new Response(['status' => 'test'])],
            [new Response(['status' => 99])],
            [new Response(['status' => 600])],
            [new Response(['status' => new StdClass])],
            [new Response(['status' => TRUE])],
            [new Response(['status' => FALSE])],
            [new Response(['status' => [1,2,3]])],
        ];
    }

    /**
     * @dataProvider provideResponsesWithBadHeaders
     * @expectedException \DomainException
     * @expectedExceptionMessage Invalid response header array
     */
    function testNormalizeThrowsOnInvalidHeaders($response) {
        $normalizer = new ResponseNormalizer;
        $normalizer->normalize($response, $request = [
            'SERVER_PROTOCOL' => '1.1'
        ]);
    }

    function provideResponsesWithBadHeaders() {
        return [
            [new Response(['headers' => 'test'])],
            [new Response(['headers' => 99])],
            [new Response(['headers' => 600])],
            [new Response(['headers' => new StdClass])],
            [new Response(['headers' => TRUE])]
        ];
    }

    /**
     * @dataProvider provideResponsesWithBadBody
     * @expectedException \DomainException
     * @expectedExceptionMessage Invalid response body
     */
    function testNormalizeThrowsOnInvalidBody($response) {
        $normalizer = new ResponseNormalizer;
        $normalizer->normalize($response, $request = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET'
        ]);
    }

    function provideResponsesWithBadBody() {
        return [
            [new Response(['body' => new StdClass])],
            [new Response(['body' => [1,2,3]])],
        ];
    }

    /**
     * @dataProvider provideNormalizationExpectations
     */
    function testNormalizationResults($response, $request, $options, $shouldCloseExpectation, $rawHeadersExpectation) {
        $normalizer = new ResponseNormalizer;
        list($actualRawHeaders, $actualShouldClose) = $normalizer->normalize($response, $request, $options);
        $this->assertEquals($shouldCloseExpectation, $actualShouldClose);
        $this->assertEquals($rawHeadersExpectation, $actualRawHeaders);
    }

    function provideNormalizationExpectations() {
        $return = [];

        // 0 -------------------------------------------------------------------------------------->

        $response = new Response([
            'body' => 'test'
        ]);
        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'HTTP_CONNECTION' => 'close',
            'REQUEST_METHOD' => 'GET'
        ];
        $options = [];

        $shouldCloseExpectation = TRUE;
        $rawHeadersExpectation =
            "HTTP/1.1 200\r\n" .
            "Connection: close\r\n" .
            "Content-Length: 4\r\n" .
            "Content-Type: text/html; charset=utf-8\r\n" .
            "\r\n"
        ;

        $return[] = [$response, $request, $options, $shouldCloseExpectation, $rawHeadersExpectation];

        // 1 -------------------------------------------------------------------------------------->

        $response = new Response([
            'body' => 'test'
        ]);
        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'HTTP_CONNECTION' => 'close',
            'REQUEST_METHOD' => 'GET'
        ];
        $options = [
            'autoReason' => TRUE
        ];

        $shouldCloseExpectation = TRUE;
        $rawHeadersExpectation =
            "HTTP/1.1 200 OK\r\n" .
            "Connection: close\r\n" .
            "Content-Length: 4\r\n" .
            "Content-Type: text/html; charset=utf-8\r\n" .
            "\r\n"
        ;

        $return[] = [$response, $request, $options, $shouldCloseExpectation, $rawHeadersExpectation];

        // 2 -------------------------------------------------------------------------------------->

        $response = new Response([
            'body' => 'test'
        ]);
        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET'
        ];
        $options = [
            'autoReason' => TRUE
        ];

        $shouldCloseExpectation = FALSE;
        $rawHeadersExpectation =
            "HTTP/1.1 200 OK\r\n" .
            "Connection: keep-alive\r\n" .
            "Content-Length: 4\r\n" .
            "Content-Type: text/html; charset=utf-8\r\n" .
            "\r\n"
        ;

        $return[] = [$response, $request, $options, $shouldCloseExpectation, $rawHeadersExpectation];

        // 3 -------------------------------------------------------------------------------------->

        $response = new Response([
            'body' => 'test'
        ]);
        $request = [
            'SERVER_PROTOCOL' => '1.0',
            'REQUEST_METHOD' => 'GET'
        ];
        $options = [
            'autoReason' => TRUE
        ];

        $shouldCloseExpectation = TRUE;
        $rawHeadersExpectation =
            "HTTP/1.0 200 OK\r\n" .
            "Connection: close\r\n" .
            "Content-Length: 4\r\n" .
            "Content-Type: text/html; charset=utf-8\r\n" .
            "\r\n"
        ;

        $return[] = [$response, $request, $options, $shouldCloseExpectation, $rawHeadersExpectation];

        // 4 -------------------------------------------------------------------------------------->

        $response = new Response([
            'body' => 'test'
        ]);
        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET'
        ];
        $options = [
            'autoReason' => TRUE,
            'forceClose' => TRUE
        ];

        $shouldCloseExpectation = TRUE;
        $rawHeadersExpectation =
            "HTTP/1.1 200 OK\r\n" .
            "Connection: close\r\n" .
            "Content-Length: 4\r\n" .
            "Content-Type: text/html; charset=utf-8\r\n" .
            "\r\n"
        ;

        $return[] = [$response, $request, $options, $shouldCloseExpectation, $rawHeadersExpectation];

        // 5 -------------------------------------------------------------------------------------->

        $response = new Response([
            'body' => 'test',
            'headers' => [
                'CONTENT-LENGTH: 42'
            ]
        ]);
        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET'
        ];
        $options = [
            'autoReason' => TRUE,
            'forceClose' => TRUE
        ];

        $shouldCloseExpectation = TRUE;
        $rawHeadersExpectation =
            "HTTP/1.1 200 OK\r\n" .
            "Connection: close\r\n" .
            "Content-Length: 4\r\n" .
            "Content-Type: text/html; charset=utf-8\r\n" .
            "\r\n"
        ;

        $return[] = [$response, $request, $options, $shouldCloseExpectation, $rawHeadersExpectation];

        // 6 -------------------------------------------------------------------------------------->

        $response = new Response([
            'body' => 'test',
            'headers' => [
                'CONTENT-LENGTH: 56',
                'Content-Length: 128',
                'content-LENGTH: 256'
            ]
        ]);
        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET'
        ];
        $options = [
            'autoReason' => TRUE,
            'forceClose' => TRUE
        ];

        $shouldCloseExpectation = TRUE;
        $rawHeadersExpectation =
            "HTTP/1.1 200 OK\r\n" .
            "Connection: close\r\n" .
            "Content-Length: 4\r\n" .
            "Content-Type: text/html; charset=utf-8\r\n" .
            "\r\n"
        ;

        $return[] = [$response, $request, $options, $shouldCloseExpectation, $rawHeadersExpectation];

        // 7 -------------------------------------------------------------------------------------->

        $body = fopen('php://memory', 'r+');
        fwrite($body, 'test');
        rewind($body);
        $response = new Response([
            'body' => $body,
            'headers' => [
                'CONTENT-LENGTH: 42'
            ]
        ]);
        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET'
        ];
        $options = [
            'autoReason' => TRUE
        ];

        $shouldCloseExpectation = FALSE;
        $rawHeadersExpectation =
            "HTTP/1.1 200 OK\r\n" .
            "Connection: keep-alive\r\n" .
            "Content-Length: 4\r\n" .
            "Content-Type: text/html; charset=utf-8\r\n" .
            "\r\n"
        ;

        $return[] = [$response, $request, $options, $shouldCloseExpectation, $rawHeadersExpectation];

        // 8 -------------------------------------------------------------------------------------->

        $response = new Response([
            'body' => 'test',
            'headers' => [
                'CONTENT-LENGTH: 56',
                'ConNECTION: keep-alive',
                'content-LENGTH: 256'
            ]
        ]);
        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET'
        ];
        $options = [
            'forceClose' => TRUE
        ];

        $shouldCloseExpectation = TRUE;
        $rawHeadersExpectation =
            "HTTP/1.1 200\r\n" .
            "Connection: close\r\n" .
            "Content-Length: 4\r\n" .
            "Content-Type: text/html; charset=utf-8\r\n" .
            "\r\n"
        ;

        $return[] = [$response, $request, $options, $shouldCloseExpectation, $rawHeadersExpectation];

        // 9 -------------------------------------------------------------------------------------->

        $response = new Response([
            'body' => 'test',
            'headers' => [
                'ConNECTION: keep-alive',
                'ConNECTION: close',
            ]
        ]);
        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET'
        ];
        $options = [];

        $shouldCloseExpectation = FALSE;
        $rawHeadersExpectation =
            "HTTP/1.1 200\r\n" .
            "Connection: keep-alive\r\n" .
            "Content-Length: 4\r\n" .
            "Content-Type: text/html; charset=utf-8\r\n" .
            "\r\n"
        ;

        $return[] = [$response, $request, $options, $shouldCloseExpectation, $rawHeadersExpectation];

        // 10 ------------------------------------------------------------------------------------->

        $response = new Response([
            'body' => 'test'
        ]);
        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET'
        ];
        $options = [
            'requestsRemaining' => 0
        ];

        $shouldCloseExpectation = TRUE;
        $rawHeadersExpectation =
            "HTTP/1.1 200\r\n" .
            "Connection: close\r\n" .
            "Content-Length: 4\r\n" .
            "Content-Type: text/html; charset=utf-8\r\n" .
            "\r\n"
        ;

        $return[] = [$response, $request, $options, $shouldCloseExpectation, $rawHeadersExpectation];

        // 11 ------------------------------------------------------------------------------------->

        $response = new Response([
            'body' => 'test'
        ]);
        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET'
        ];
        $options = [
            'requestsRemaining' => 10,
            'keepAliveTimeout' => 5
        ];

        $shouldCloseExpectation = FALSE;
        $rawHeadersExpectation =
            "HTTP/1.1 200\r\n" .
            "Connection: keep-alive\r\n" .
            "Keep-Alive: timeout=5, max=10\r\n" .
            "Content-Length: 4\r\n" .
            "Content-Type: text/html; charset=utf-8\r\n" .
            "\r\n"
        ;

        $return[] = [$response, $request, $options, $shouldCloseExpectation, $rawHeadersExpectation];

        // 12 ------------------------------------------------------------------------------------->

        $response = new Response([
            'body' => 'test',
            'headers' => [
                'Connection: keep-alive'
            ]
        ]);
        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET'
        ];
        $options = [
            'requestsRemaining' => 10,
            'keepAliveTimeout' => 5
        ];

        $shouldCloseExpectation = FALSE;
        $rawHeadersExpectation =
            "HTTP/1.1 200\r\n" .
            "Connection: keep-alive\r\n" .
            "Keep-Alive: timeout=5, max=10\r\n" .
            "Content-Length: 4\r\n" .
            "Content-Type: text/html; charset=utf-8\r\n" .
            "\r\n"
        ;

        $return[] = [$response, $request, $options, $shouldCloseExpectation, $rawHeadersExpectation];

        // 13 ------------------------------------------------------------------------------------->

        $response = new Response([
            'body' => 'test'
        ]);
        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET'
        ];
        $options = [
            'serverToken' => 'zanzibar',
            'dateHeader' => 'some date'
        ];

        $shouldCloseExpectation = FALSE;
        $rawHeadersExpectation =
            "HTTP/1.1 200\r\n" .
            "Connection: keep-alive\r\n" .
            "Content-Length: 4\r\n" .
            "Content-Type: text/html; charset=utf-8\r\n" .
            "Server: zanzibar\r\n" .
            "Date: some date\r\n" .
            "\r\n"
        ;

        $return[] = [$response, $request, $options, $shouldCloseExpectation, $rawHeadersExpectation];

        // 14 ------------------------------------------------------------------------------------->

        $response = new Response([
            'status' => 101,
            'reason' => 'Switching Protocols',
            'headers' => [
                'Connection: upgrade, keep-alive'
            ]
        ]);
        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'GET'
        ];
        $options = [];

        $shouldCloseExpectation = FALSE;
        $rawHeadersExpectation =
            "HTTP/1.1 101 Switching Protocols\r\n" .
            "Connection: upgrade, keep-alive\r\n" .
            "\r\n"
        ;

        $return[] = [$response, $request, $options, $shouldCloseExpectation, $rawHeadersExpectation];

        // x -------------------------------------------------------------------------------------->

        return $return;
    }

    function testNormalizeRemovesBodyOnHeadMethod() {
        $response = new Response([
            'body' => 'test'
        ]);
        $request = [
            'SERVER_PROTOCOL' => '1.1',
            'REQUEST_METHOD' => 'HEAD'
        ];
        $normalizer  = new ResponseNormalizer;
        $normalizer->normalize($response, $request);
        $this->assertEquals('', $response['body']);

    }

}

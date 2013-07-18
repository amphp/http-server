<?php

require dirname(__DIR__) . '/bootstrap.php';

class TestingApp {
    
    function __invoke(array $asgiEnv, $requestId) {
        switch ($asgiEnv['REQUEST_URI']) {
            case '/':
                return $this->baseResponse($asgiEnv, $requestId);
            case '/adds_missing_headers':
                return $this->addsMissingHeaders($asgiEnv, $requestId);
            case '/adds_missing_content_type_charset':
                return $this->addsMissingContentTypeCharset($asgiEnv, $requestId);
            case '/returns_post_body':
                return $this->returnsPostBody($asgiEnv, $requestId);
            case '/returns_put_body':
                return $this->returnsPutBody($asgiEnv, $requestId);
            default:
                throw new \Exception(
                    'Test endpoint not implemented'
                );
        }
    }
    
    function baseResponse() {
        return [
            $status = 200,
            $reason = 'OK',
            $headers = [],
            $body = '<html><body><h1>Hello, World.</h1></body></html>'
        ];
    }
    
    /**
     * @expectedHeader Content-Type: text/html; charset=utf-8
     * @expectedHeader Content-Length: strlen($body)
     * @expectedHeader Date: date(Server::HTTP_DATE)
     */
    function addsMissingHeaders() {
        return $this->baseResponse();
    }
    
    /**
     * @expectedHeader Content-Type: text/plain; charset=utf-8
     */
    function addsMissingContentTypeCharset() {
        return [
            $status = 200,
            $reason = 'OK',
            $headers = [
                'Content-Type' => 'text/plain'
            ],
            $body = 'Hello, World.'
        ];
    }
    
    function returnsPostBody(array $asgiEnv) {
        if ($asgiEnv['REQUEST_METHOD'] === 'POST') {
            $asgiResponse = [
                $status = 200,
                $reason = 'OK',
                $headers = [],
                $body = $asgiEnv['ASGI_INPUT']
            ];
        } else {
            $asgiResponse = [499, 'Bad test call: POST method expected', [], 'Invalid test usage'];
        }
        
        return $asgiResponse;
    }
    
    function returnsPutBody(array $asgiEnv) {
        if ($asgiEnv['REQUEST_METHOD'] === 'PUT') {
            $asgiResponse = [
                $status = 200,
                $reason = 'OK',
                $headers = [],
                $body = $asgiEnv['ASGI_INPUT']
            ];
        } else {
            $asgiResponse = [499, 'Bad test call: PUT method expected', [], 'Invalid test usage'];
        }
        
        return $asgiResponse;
    }
}

(new Aerys\Config\Bootstrapper)->createServer([
    'options' => [
        'verbosity' => 0
    ],
    'test-server' => [
        'listenOn'      => '*:1500',
        'application'   => new TestingApp
    ]
])->start();

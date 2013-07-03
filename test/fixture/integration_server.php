<?php

require dirname(__DIR__) . '/bootstrap.php';

class TestingApp {
    
    function __invoke(array $asgiEnv, $requestId) {
        switch ($asgiEnv['REQUEST_URI']) {
            case '/':
                return $this->baseResponse();
            case '/adds_missing_headers':
                return $this->addsMissingHeaders();
            case '/adds_missing_content_type_charset':
                return $this->addsMissingContentTypeCharset();
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
}

(new Aerys\Config\Configurator)->createServer([[
    'listenOn'      => '*:1500',
    'application'   => new TestingApp
]])->start();

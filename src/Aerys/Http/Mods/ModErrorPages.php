<?php

namespace Aerys\Http\Mods;

use Aerys\Http\HttpServer;

class ModErrorPages implements BeforeResponseMod {
    
    private $httpServer;
    private $errorPages;
    
    function __construct(HttpServer $httpServer, array $config) {
        $this->httpServer = $httpServer;
        
        foreach ($config as $statusCode => $pageAndContentType) {
            $filePath = $pageAndContentType[0];
            $contentType = isset($pageAndContentType[1]) ? $pageAndContentType[1] : NULL;
            
            if (is_readable($filePath) && is_file($filePath)) {
                $content = file_get_contents($filePath);
                $contentLength = strlen($content);
                $this->errorPages[$statusCode] = [$content, $contentLength, $contentType];
            } else {
                throw new \RuntimeException(
                    "The specified file path could not be read: $filePath"
                );
            }
        }
    }
    
    function beforeResponse($requestId) {
        list($status, $reason, $headers, $body) = $this->httpServer->getResponse($requestId);
        
        if ($status >= 400 && isset($this->errorPages[$status])) {
            list($body, $contentLength, $contentType) = $this->errorPages[$status];
            $headers['CONTENT-LENGTH'] = $contentLength;
            if (NULL !== $contentType) {
                $headers['CONTENT-TYPE'] = $contentType;
            }
            
            $this->httpServer->setResponse($requestId, [$status, $reason, $headers, $body]);
        }
    }
}


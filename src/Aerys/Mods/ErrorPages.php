<?php

namespace Aerys\Mods;

use Aerys\Server;

class ErrorPages implements BeforeResponseMod {
    
    private $errorPages;
    
    function configure(array $config) {
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
    
    function beforeResponse(Server $server, $requestId) {
        list($status, $reason, $headers, $body) = $server->getResponse($requestId);
        
        if ($status >= 400 && isset($this->errorPages[$status])) {
            list($body, $contentLength, $contentType) = $this->errorPages[$status];
            $headers['CONTENT-LENGTH'] = $contentLength;
            if (NULL !== $contentType) {
                $headers['CONTENT-TYPE'] = $contentType;
            }
            
            $server->setResponse($requestId, [$status, $reason, $headers, $body]);
        }
    }
}


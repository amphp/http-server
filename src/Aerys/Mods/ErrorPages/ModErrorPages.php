<?php

namespace Aerys\Mods\ErrorPages;

use Aerys\Server,
    Aerys\Mods\BeforeResponseMod;

class ModErrorPages implements BeforeResponseMod {
    
    private $httpServer;
    private $errorPages;
    
    function __construct(Server $httpServer, array $config) {
        $this->httpServer = $httpServer;
        
        foreach ($config as $statusCode => $pageAndContentType) {
            $filePath = $pageAndContentType[0];
            $contentType = isset($pageAndContentType[1]) ? $pageAndContentType[1] : NULL;
            
            if (is_readable($filePath) && is_file($filePath)) {
                $content = file_get_contents($filePath);
                $this->errorPages[$statusCode] = [$content, $contentType];
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
            list($body, $contentType) = $this->errorPages[$status];
            if ($contentType) {
                $headers = $this->assignContentTypeHeader($headers, $contentType);
            }
            
            $this->httpServer->setResponse($requestId, [$status, $reason, $headers, $body]);
        }
    }
    
    private function assignContentTypeHeader($headers, $contentType) {
        $headers = $this->stringifyResponseHeaders($headers);
        $ctPos = stripos($headers, "\r\nContent-Type:");
        
        $newHeader = "\r\nContent-Type: {$contentType}";
        
        if ($ctPos === FALSE) {
            $headers .= $newHeader;
        } else {
            $lineEndPos = strpos($headers, "\r\n", $ctPos + 2);
            $start = substr($headers, 0, $ctPos);
            $end = substr($headers, $lineEndPos);
            $headers = $start . $newHeader . $end;
        }
        
        return ltrim($headers);
    }
    
    private function stringifyResponseHeaders($headers) {
        if (!$headers) {
            $headers = '';
        } elseif (is_array($headers)) {
            $headers = implode("\r\n", array_map('trim', $headers));
        } elseif (is_string($headers)) {
            $headers = implode("\r\n", array_map('trim', explode("\n", $headers)));
        } else {
            throw new \UnexpectedValueException(
                'Invalid response headers'
            );
        }
        
        return $headers;
    }
}


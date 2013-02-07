<?php

namespace Aerys\Mods;

use Aerys\Server,
    Aerys\Engine\EventBase;

class ErrorPages implements BeforeResponseMod {
    
    const X_ERROR_PAGES_NO_MODIFY = 'X-ERROR-PAGES-NO-MODIFY';
    
    private $server;
    private $errorPages;
    
    static function createMod(Server $server, EventBase $eventBase, array $config) {
        $class = __CLASS__;
        return new $class($server, $config);
    }
    
    function __construct(Server $server, array $config) {
        $this->server = $server;
        
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
    
    function beforeResponse($clientId, $requestId) {
        list($status, $reason, $headers, $body) = $this->server->getResponse($requestId);
        
        if (isset($headers[self::X_ERROR_PAGES_NO_MODIFY])) {
            unset($headers[self::X_ERROR_PAGES_NO_MODIFY]);
            $this->server->setResponse($requestId, [$status, $reason, $headers, $body]);
            
        } elseif ($status >= 400 && isset($this->errorPages[$status])) {
            list($body, $contentLength, $contentType) = $this->errorPages[$status];
            $headers['CONTENT-LENGTH'] = $contentLength;
            if (NULL !== $contentType) {
                $headers['CONTENT-TYPE'] = $contentType;
            }
            
            $this->server->setResponse($requestId, [$status, $reason, $headers, $body]);
        }
    }
}


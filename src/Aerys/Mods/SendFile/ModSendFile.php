<?php

namespace Aerys\Mods\SendFile;

use Aerys\Server,
    Aerys\Responders\Static\Docs;

class ModSendFile {

    private $server;
    private $docRootResponder;

    function __construct(Server $server, Docs $docRootResponder = NULL) {
        $this->server = $server;
        $this->docRootResponder = $docRootResponder ?: new Docs;
    }

    function beforeResponse($requestId) {
        $asgiResponse = $this->server->getResponse($requestId);
        
        // If the response isn't an array then there are no headers
        // and there's nothing for us to do
        if (!is_array($asgiResponse)) {
            return;
        }
        
        $headers = $this->stringifyResponseHeaders($asgiResponse[2]);

        $sfPos = stripos($headers, "\r\nX-SendFile:");

        if ($sfPos !== FALSE) {
            $lineEndPos = strpos($headers, "\r\n", $sfPos + 2);
            $headerLine = substr($headers, $sfPos + 2, $lineEndPos - $sfPos);
            $filePath = '/' . trim(explode(':', $headerLine, 2)[1], "\r\n/\\ ");

            /*
            // @TODO Retain original headers and merge them with the new headers from DocRoot
            $start = substr($headers, 0, $sfPos);
            $end = substr($headers, $lineEndPos);
            $headers = $start . $end;
            */

            $asgiEnv = $this->server->getRequest($requestId);
            $oldRequestUri = $asgiEnv['REQUEST_URI'];
            $newRequestUri = '/' . ltrim($filePath, '/');
            $asgiEnv['REQUEST_URI'] = $newRequestUri;

            $asgiResponse = $this->docRootResponder->__invoke($asgiEnv, $requestId);

            $this->server->setResponse($requestId, $asgiResponse);
        }
    }

    private function stringifyResponseHeaders($headers) {
        if (!$headers) {
            $headers = '';
        } elseif (is_array($headers)) {
            $headers = "\r\n" . implode("\r\n", array_map('trim', $headers)) . "\r\n";
        } elseif (is_string($headers)) {
            $headers = "\r\n" . implode("\r\n", array_map('trim', explode("\n", $headers))) .  "\r\n";
        } else {
            throw new \UnexpectedValueException(
                'Invalid response headers'
            );
        }

        return $headers;
    }

}

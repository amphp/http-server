<?php

namespace Aerys\Handlers\Websocket;

use Aerys\Status,
    Aerys\Reason,
    Aerys\Server;

class Handler {
    
    const ACCEPT_CONCATENATOR = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    
    private $sessionManager;
    private $sessionFactory;
    private $endpoints = [];
    private $supportedVersions = [13 => TRUE];
    
    function __construct(SessionManager $sessMgr, array $endpoints, SessionFactory $sf = NULL) {
        $this->sessionManager = $sessMgr;
        $this->sessionFactory = $sf ?: new SessionFactory;
        
        if ($this->validateEndpoints($endpoints)) {
            $this->endpoints = $endpoints;
        } else {
            throw new \InvalidArgumentException(
                'Endpoint list must specify an array mapping URI keys to Endpoint instances'
            );
        }
    }
    
    private function validateEndpoints(array $endpoints) {
        if (empty($endpoints)) {
            return FALSE;
        }
        
        foreach ($endpoints as $uri => $endpoint) {
            if (!$endpoint instanceof Endpoint) {
                return FALSE;
            }
        }
        
        return TRUE;
    }
    
    function __invoke(array $asgiEnv) {
        list($isAccepted, $handshakeResult) = $this->validateClientHandshake($asgiEnv);
        
        if ($isAccepted) {
            list($version, $protocol, $extensions) = $handshakeResult;
            $response = $this->generateServerHandshake($asgiEnv, $version, $protocol, $extensions);
        } else {
            $response = $handshakeResult;
        }
        
        return $response;
    }
    
    private function validateClientHandshake(array $asgiEnv) {
        $requestUri = $asgiEnv['REQUEST_URI'];
        
        if (isset($this->endpoints[$requestUri])) {
            $endpointOpts = $this->endpoints[$requestUri]->getOptions();
        } else {
            return [FALSE, [Status::NOT_FOUND, Reason::HTTP_404, [], NULL]];
        }
        
        if (($beforeHandshake = $endpointOpts->getBeforeHandshake())
            && ($result = $this->beforeHandshake($beforeHandshake, $asgiEnv))
        ) {
            return [FALSE, $result];
        }
        
        if ($asgiEnv['REQUEST_METHOD'] != 'GET') {
            return [FALSE, [Status::METHOD_NOT_ALLOWED, Reason::HTTP_405, [], NULL]];
        }
        
        if ($asgiEnv['SERVER_PROTOCOL'] < 1.1) {
            return [FALSE, [Status::HTTP_VERSION_NOT_SUPPORTED, Reason::HTTP_505, [], NULL]];
        }
        
        if (empty($asgiEnv['HTTP_UPGRADE']) || strcasecmp($asgiEnv['HTTP_UPGRADE'], 'websocket')) {
            return [FALSE, [Status::UPGRADE_REQUIRED, Reason::HTTP_426, [], NULL]];
        }
        
        if (empty($asgiEnv['HTTP_CONNECTION']) || !stristr($asgiEnv['HTTP_CONNECTION'], 'upgrade')) {
            $reason = 'Bad Request: "Connection: Upgrade" header required';
            return [FALSE, [Status::BAD_REQUEST, $reason, [], NULL]];
        }
        
        if (empty($asgiEnv['HTTP_SEC_WEBSOCKET_KEY'])) {
            $reason = 'Bad Request: "Sec-Websocket-Key" header required';
            return [FALSE, [Status::BAD_REQUEST, $reason, [], NULL]];
        }
        
        if (empty($asgiEnv['HTTP_SEC_WEBSOCKET_VERSION'])) {
            $reason = 'Bad Request: "Sec-WebSocket-Version" header required';
            return [FALSE, [Status::BAD_REQUEST, $reason, [], NULL]];
        }
        
        $version = NULL;
        $requestedVersions = explode(',', $asgiEnv['HTTP_SEC_WEBSOCKET_VERSION']);
        foreach ($requestedVersions as $requestedVersion) {
            if (isset($this->supportedVersions[$requestedVersion])) {
                $version = $requestedVersion;
                break;
            }
        }
        
        if (!$version) {
            $reason = 'Bad Request: Requested WebSocket version(s) unavailable';
            $headers = ['Sec-WebSocket-Version' => implode(',', $this->supportedVersions)];
            return [FALSE, [Status::BAD_REQUEST, $reason, $headers, NULL]];
        }
        
        $allowedOrigins = $endpointOpts->getAllowedOrigins();
        $originHeader = empty($asgiEnv['HTTP_ORIGIN']) ? NULL : $asgiEnv['HTTP_ORIGIN'];
        if ($allowedOrigins && !in_array($originHeader, $allowedOrigins)) {
            return [FALSE, [Status::FORBIDDEN, Reason::HTTP_403, [], NULL]];
        }
        
        $subprotocol = $endpointOpts->getSubprotocol();
        $subprotocolHeader = !empty($asgiEnv['HTTP_SEC_WEBSOCKET_PROTOCOL'])
            ? explode(',', $asgiEnv['HTTP_SEC_WEBSOCKET_PROTOCOL'])
            : [];
        
        if ($subprotocol && !in_array($subprotocol, $subprotocolHeader)) {
            $reason = 'Bad Request: Requested WebSocket subprotocol(s) unavailable';
            return [FALSE, [Status::BAD_REQUEST, $reason, [], NULL]];
        }
        
        /**
         * @TODO Negotiate supported Sec-WebSocket-Extensions
         * 
         * The Sec-WebSocket-Extensions header field is used to select protocol-level extensions as
         * outlined in RFC 6455 Section 9.1:
         * 
         * http://tools.ietf.org/html/rfc6455#section-9.1
         * 
         * As of 2013-03-08 no extensions have been registered with the IANA:
         * 
         * http://www.iana.org/assignments/websocket/websocket.xml#extension-name
         */
        $extensions = [];
        
        return [TRUE, [$version, $subprotocol, $extensions]];
    }
    
    private function beforeHandshake(callable $beforeHandshake, array $asgiEnv) {
        try {
            return $beforeHandshake($asgiEnv);
        } catch (\Exception $e) {
            @fwrite($asgiEnv['ASGI_ERROR'], $e);
            return [Status::INTERNAL_SERVER_ERROR, Reason::HTTP_500, [], NULL];
        }
    }
    
    private function generateServerHandshake(array $asgiEnv, $version, $subprotocol, $extensions) {
        $concatenatedKeyStr = $asgiEnv['HTTP_SEC_WEBSOCKET_KEY'] . self::ACCEPT_CONCATENATOR;
        $secWebSocketAccept = base64_encode(sha1($concatenatedKeyStr, TRUE));
        
        $headers = [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Accept' => $secWebSocketAccept
        ];
        
        if ($subprotocol || $subprotocol === '0') {
            $headers['Sec-WebSocket-Protocol'] = $subprotocol;
        }
        
        if ($extensions) {
            $headers['Sec-WebSocket-Extensions'] = implode(',', $extensions);
        }
        
        return [Status::SWITCHING_PROTOCOLS, Reason::HTTP_101, $headers, NULL, [$this, 'importSocket']];
    }
    
    function importSocket($socket, array $asgiEnv) {
        $endpoint = $this->endpoints[$asgiEnv['REQUEST_URI']];
        $this->sessionManager->open($socket, $endpoint, $asgiEnv);
    }
    
}


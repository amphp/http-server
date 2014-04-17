<?php

namespace Aerys\Websocket;

use Alert\Reactor,
    Alert\Aggregate,
    Alert\Success,
    Aerys\Server,
    Aerys\Status,
    Aerys\Response,
    Aerys\ServerObserver;

class Responder implements ServerObserver {
    const ACCEPT_CONCAT = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    private $reactor;
    private $endpoints = [];

    public function __construct(Reactor $reactor) {
        $this->reactor = $reactor;
    }

    public function setEndpoint($wsUriPath, Endpoint $wsEndpoint) {
        $this->endpoints[$wsUriPath] = $wsEndpoint;
    }

    /**
     * Conduct the websocket handshake and respond appropriately
     *
     * @param array $request The request environment map
     * @return \Aerys\Response
     */
    public function __invoke($request) {
        $uriPath = $request['REQUEST_URI_PATH'];
        $response = new Response;

        if (isset($this->endpoints[$uriPath])) {
            $endpoint = $this->endpoints[$uriPath];
        } else {
            return $response->setStatus(Status::NOT_FOUND);
        }

        if ($request['REQUEST_METHOD'] != 'GET') {
            return $response->setStatus(Status::METHOD_NOT_ALLOWED);
        }

        if ($request['SERVER_PROTOCOL'] < 1.1) {
            return $response->setStatus(Status::HTTP_VERSION_NOT_SUPPORTED);
        }

        if (empty($request['HTTP_UPGRADE'])
            || strcasecmp($request['HTTP_UPGRADE'], 'websocket')
        ) {
            return $response->setStatus(Status::UPGRADE_REQUIRED);
        }

        if (empty($request['HTTP_CONNECTION'])
            || !$this->validateConnectionHeader($request['HTTP_CONNECTION'])
        ) {
            $reason = 'Bad Request: "Connection: Upgrade" header required';
            return $response->setStatus(Status::BAD_REQUEST)->setReason($reason);
        }

        if (empty($request['HTTP_SEC_WEBSOCKET_KEY'])) {
            $reason = 'Bad Request: "Sec-Broker-Key" header required';
            return $response->setStatus(Status::BAD_REQUEST)->setReason($reason);
        }

        if (empty($request['HTTP_SEC_WEBSOCKET_VERSION'])) {
            $reason = 'Bad Request: "Sec-WebSocket-Version" header required';
            return $response->setStatus(Status::BAD_REQUEST)->setReason($reason);
        }

        $version = NULL;
        $requestedVersions = explode(',', $request['HTTP_SEC_WEBSOCKET_VERSION']);
        foreach ($requestedVersions as $requestedVersion) {
            if ($requestedVersion === '13') {
                $version = 13;
                break;
            }
        }

        if (empty($version)) {
            return $response->setStatus(Status::BAD_REQUEST)
                ->setReason('Bad Request: Requested WebSocket version(s) unavailable')
                ->setHeader('Sec-WebSocket-Version', '13')
            ;
        }

        $originHeader = empty($request['HTTP_ORIGIN']) ? NULL : $request['HTTP_ORIGIN'];
        if ($originHeader && !$endpoint->allowsOrigin($originHeader)) {
            return $response->setStatus(Status::FORBIDDEN);
        }

        $subprotocol = NULL;
        $reqSubprotos = !empty($request['HTTP_SEC_WEBSOCKET_PROTOCOL'])
            ? explode(',', $request['HTTP_SEC_WEBSOCKET_PROTOCOL'])
            : [];

        if ($reqSubprotos && (!$subprotocol = $endpoint->negotiateSubprotocol($reqSubprotos))) {
            $reason = 'Bad Request: Requested WebSocket subprotocol(s) unavailable';
            return $response->setStatus(Status::BAD_REQUEST)->setReason($reason);
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

        $concatKeyStr = $request['HTTP_SEC_WEBSOCKET_KEY'] . self::ACCEPT_CONCAT;
        $secWebSocketAccept = base64_encode(sha1($concatKeyStr, TRUE));

        $headers = [
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Accept: {$secWebSocketAccept}"
        ];

        if ($subprotocol) {
            $headers[] = "Sec-WebSocket-Protocol: {$subprotocol}";
        }

        if ($extensions) {
            $headers[] = 'Sec-WebSocket-Extensions: ' . implode(',', $extensions);
        }

        $response->setStatus(Status::SWITCHING_PROTOCOLS);
        $response->applyRawHeaderLines($headers);
        $response->setExportCallback([$endpoint, 'import']);

        return $response;
    }

    /**
     * Some browsers send multiple connection headers e.g. `Connection: keep-alive, Upgrade` so it's
     * necessary to check for the upgrade value as part of a comma-delimited list.
     */
    private function validateConnectionHeader($header) {
        $hasConnectionUpgrade = FALSE;

        if (!strcasecmp($header, 'upgrade')) {
            $hasConnectionUpgrade = TRUE;
        } elseif (strstr($header, ',')) {
            $parts = explode(',', $header);
            foreach ($parts as $part) {
                if (!strcasecmp(trim($part), 'upgrade')) {
                    $hasConnectionUpgrade = TRUE;
                    break;
                }
            }
        }

        return $hasConnectionUpgrade;
    }

    /**
     * Listen for server START/STOPPING notifications
     *
     * Websocket endpoints require notification when the server starts or stops to enable
     * application bootstrapping and graceful shutdown. The Responder returns a Future when
     * notified that will resolve when its start/stop routines complete.
     *
     * @param \Aerys\Server $server
     * @param int $event
     * @return \Alert\Future
     */
    public function onServerUpdate(Server $server, $event) {
        switch ($event) {
            case Server::STARTING:
                $method = 'start';
                break;
            case Server::STOPPING:
                $method = 'stop';
                break;
            default:
                return;
        }

        $futures = [];
        foreach ($this->endpoints as $endpoint) {
            $futures[] = call_user_func([$endpoint, $method]);
        }

        return $futures ? Aggregate::all($futures) : new Success;
    }
}

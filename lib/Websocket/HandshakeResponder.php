<?php

namespace Aerys\Websocket;

use Alert\Reactor,
    Aerys\Server,
    Aerys\Status,
    Aerys\Response,
    Aerys\ServerObserver;

class HandshakeResponder implements ServerObserver {
    const ACCEPT_CONCAT = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    private $reactor;
    private $server;
    private $isStopping;

    public function __construct(Reactor $reactor, Server $server) {
        $this->reactor = $reactor;
        $this->server = $server;
    }

    /**
     * Conduct the websocket handshake and respond appropriately
     *
     * @param Aerys\Websocket\Endpoint $endpoint
     * @param array $request The HTTP request environment map
     * @return mixed[Aerys\Response|Aerys\Writer]
     */
    public function handshake(Endpoint $endpoint, array $request) {
        if ($this->isStopping) {
            return (new Response)->setStatus(Status::SERVICE_UNAVAILABLE);
        }

        if ($request['REQUEST_METHOD'] != 'GET') {
            return (new Response)->setStatus(Status::METHOD_NOT_ALLOWED);
        }

        if ($request['SERVER_PROTOCOL'] < 1.1) {
            return (new Response)->setStatus(Status::HTTP_VERSION_NOT_SUPPORTED);
        }

        if (empty($request['HTTP_UPGRADE']) || strcasecmp($request['HTTP_UPGRADE'], 'websocket') !== 0) {
            return (new Response)->setStatus(Status::UPGRADE_REQUIRED);
        }

        if (empty($request['HTTP_CONNECTION']) || stripos($request['HTTP_CONNECTION'], 'Upgrade') === FALSE) {
            $reason = 'Bad Request: "Connection: Upgrade" header required';
            return (new Response)->setStatus(Status::BAD_REQUEST)->setReason($reason);
        }

        if (empty($request['HTTP_SEC_WEBSOCKET_KEY'])) {
            $reason = 'Bad Request: "Sec-Broker-Key" header required';
            return (new Response)->setStatus(Status::BAD_REQUEST)->setReason($reason);
        }

        if (empty($request['HTTP_SEC_WEBSOCKET_VERSION'])) {
            $reason = 'Bad Request: "Sec-WebSocket-Version" header required';
            return (new Response)->setStatus(Status::BAD_REQUEST)->setReason($reason);
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
            return (new Response)->setStatus(Status::BAD_REQUEST)
                ->setReason('Bad Request: Requested WebSocket version(s) unavailable')
                ->setHeader('Sec-WebSocket-Version', '13')
            ;
        }

        $originHeader = empty($request['HTTP_ORIGIN']) ? NULL : $request['HTTP_ORIGIN'];
        if ($originHeader && !$endpoint->allowsOrigin($originHeader)) {
            return (new Response)->setStatus(Status::FORBIDDEN);
        }

        $subprotocol = NULL;
        $reqSubprotos = !empty($request['HTTP_SEC_WEBSOCKET_PROTOCOL'])
            ? explode(',', $request['HTTP_SEC_WEBSOCKET_PROTOCOL'])
            : [];

        if ($reqSubprotos && (!$subprotocol = $endpoint->negotiateSubprotocol($reqSubprotos))) {
            $reason = 'Bad Request: Requested WebSocket subprotocol(s) unavailable';
            return (new Response)->setStatus(Status::BAD_REQUEST)->setReason($reason);
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

        $headers = implode("\r\n", $headers);
        $response = "HTTP/1.1 101 Switching Protocols\r\n{$headers}\r\n\r\n";

        return new HandshakeWriter($this->reactor, $this->server, $endpoint, $request, $response);
    }

    /**
     * Listen for server STOPPING notifications
     *
     * If the Server notifies us that it wants to shutdown we shouldn't accept new connections.
     * Store this information so we can return an appropriate response if a request arrives
     * while the server is trying to stop.
     *
     * @param Aerys\Server $server
     * @param int $event
     */
    public function onServerUpdate(Server $server, $event) {
        if ($event === Server::STOPPING) {
            $this->isStopping = TRUE;
        }
    }
}

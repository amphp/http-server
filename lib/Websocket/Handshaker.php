<?php

namespace Aerys\Websocket;

use Aerys\Status;

class Handshaker {
    private $endpoints;

    /**
     * @param array $endpoints An array mapping uri path keys to Endpoint instances
     * @throws \DomainException on invalid endpoint array
     */
    public function __construct(array $endpoints) {
        if (empty($endpoints)) {
            throw new \DomainException(
                'Non-empty array mapping URI paths to websocket endpoint instances required'
            );
        }
        foreach ($endpoints as $uri => $endpoint) {
            if (!$endpoint instanceof Endpoint) {
                throw new \DomainException(
                    "Invalid element at key {$uri}; Aerys\\Websocket\\Endpoint instance expected"
                );
            }
        }
        $this->endpoints = $endpoints;
    }

    /**
     * Conduct the websocket handshake and respond appropriately
     *
     * The following headers must be negotiated/added manually by user applications
     * inside the Websocket::onOpen() method:
     *
     *     - Sec-WebSocket-Extensions
     *     - Sec-WebSocket-Protocol
     *
     * Additionally, if an application wishes to limit connections against Origin
     * headers they must manually verify the "HTTP_ORIGIN" header key in the HTTP
     * request environment passed to Websocket::onOpen().
     *
     * @param Aerys\Websocket\Endpoint $endpoint
     * @param array $request The HTTP request environment map
     * @return mixed array|\Aerys\Websocket\HandshakeResponder
     */
    public function __invoke(array $request) {
        $uriPath = $request['REQUEST_URI_PATH'];
        if (isset($this->endpoints[$uriPath])) {
            $endpoint = $this->endpoints[$uriPath];
        } else {
            return [
                'status' => Status::NOT_FOUND
            ];
        }

        if ($request['REQUEST_METHOD'] != 'GET') {
            return [
                'status' => Status::METHOD_NOT_ALLOWED
            ];
        }

        if ($request['SERVER_PROTOCOL'] < 1.1) {
            return [
                'status' => Status::HTTP_VERSION_NOT_SUPPORTED
            ];
        }

        if (empty($request['HTTP_UPGRADE']) || strcasecmp($request['HTTP_UPGRADE'], 'websocket') !== 0) {
            return [
                'status' => Status::UPGRADE_REQUIRED
            ];
        }

        if (empty($request['HTTP_CONNECTION']) || stripos($request['HTTP_CONNECTION'], 'Upgrade') === FALSE) {
            return [
                'status' => Status::BAD_REQUEST,
                'reason' => 'Bad Request: "Connection: Upgrade" header required',
            ];
        }

        if (empty($request['HTTP_SEC_WEBSOCKET_KEY'])) {
            return [
                'status' => Status::BAD_REQUEST,
                'reason' => 'Bad Request: "Sec-Broker-Key" header required',
            ];
        }

        if (empty($request['HTTP_SEC_WEBSOCKET_VERSION'])) {
            return [
                'status' => Status::BAD_REQUEST,
                'reason' => 'Bad Request: "Sec-WebSocket-Version" header required',
            ];
        }

        $version = null;
        $requestedVersions = explode(',', $request['HTTP_SEC_WEBSOCKET_VERSION']);
        foreach ($requestedVersions as $requestedVersion) {
            if ($requestedVersion === '13') {
                $version = 13;
                break;
            }
        }

        if (empty($version)) {
            return [
                'status' => Status::BAD_REQUEST,
                'reason' => 'Bad Request: Requested WebSocket version(s) unavailable',
                'header' => 'Sec-WebSocket-Version: 13',
            ];
        }

        return new HandshakeResponder($endpoint);
    }
}

<?php

namespace Aerys\Mods\Upgrade;

use Aerys\Server,
    Aerys\Mods\OnHeadersMod;

/**
 * Upgrade HTTP connections to the TCP protocol of your choice
 * 
 * Applications must specify the "upgradeToken" key establishing how to recognize upgrade requests.
 * If the client request does not specify this token in the HTTP `Ugrade:` header ModUpgrade will
 * respond with a 426 Upgrade Required response.
 * 
 * The other required config value is the "upgradeResponder" key. This must be a userland PHP 
 * callable that returns one of three values:
 * 
 *  1. Return NULL to indicate that the ASGI request environment failed the application's requirements
 *     to allow the connection upgrade. A NULL return value will cause ModUpgrade to respond to the
 *     client request with a 426 Upgrade Required response.
 *  2. Return an ASGI response array of the application's choosing. This response will be directly
 *     relayed to the client.
 *  3. Return a valid PHP callable to receive the raw socket stream after the successful upgrade.
 *     This callback will be passed two arguments: the raw socket stream and the original request's
 *     ASGI environment array. Once the callback is invoked the Aerys HTTP server clears any 
 *     reference to the socket.
 * 
 * An example of the mod configuration block for a host using ModUpgrade might look like:
 * 
 *     'mods' => [
 *         'log' => [
 *             'php://stdout' => 'common'
 *         ],
 *         'upgrade' => [
 *             '/websocket-chat' => [
 *                 'upgradeToken' => 'websocket',
 *                 'upgradeResponder' => $callable
 *             ],
 *             '/custom-protocol-chat' => [
 *                 'upgradeToken' => 'my-custom-tcp-protocol',
 *                 'upgradeResponder' => $callable
 *             ]
 *         ]
 *     ]
 * 
 * Requirements for a valid client upgrade HTTP request using ModUpgrade:
 * 
 *  - A request protocol of 1.1 ... e.g. a request line such as `GET /chat HTTP/1.1`
 *  - The HTTP GET method is required
 *  - A `Connection: Upgrade` header
 *  - An `Upgrade: protocol-name` header where "protocol-name" matches the upgrade token value
 *    assigned to the relevant ModUpgrade endpoint
 */
class ModUpgrade implements OnHeadersMod {
    
    private $server;
    private $endpoints;
    private static $switchingProtocols = [
        101,
        'Switching Protocols',
        [],
        NULL
    ];
    private static $upgradeRequired = [
        426,
        'Upgrade Required',
        ['Connection' => 'close', 'Content-Type' => 'text/html'],
        '<html><body><h1>426 Upgrade Required</h1></body></html>'
    ];
    
    function __construct(Server $server, array $config) {
        $this->server = $server;
        
        foreach ($config as $requestUri => $endpointSettings) {
            $this->setEndpoint($requestUri, $endpointSettings);
        }
    }
    
    private function setEndpoint($requestUri, array $settings) {
        if ($requestUri[0] !== '/') {
            throw new \InvalidArgumentException(
                'Endpoint URI must begin with a backslash /'
            );
        } elseif (empty($settings['upgradeResponder']) || !is_callable($settings['upgradeResponder'])) {
            throw new \InvalidArgumentException(
                'Endpoint configuration must specify an `upgradeResponder` callback to assign an ' .
                'ASGI response and/or socket import callback'
            );
        } elseif (empty($settings['upgradeToken']) || !is_string($settings['upgradeToken'])) {
            throw new \InvalidArgumentException(
                'Endpoint configuration must specify a string `upgradeToken` to match the client ' .
                'request Upgrade header'
            );
        }
        
        $this->endpoints[$requestUri] = [
            'upgradeToken' => $settings['upgradeToken'],
            'upgradeResponder' => $settings['upgradeResponder']
        ];
    }
    
    function onHeaders($requestId) {
        $asgiEnv = $this->server->getRequest($requestId);
        
        $requestUri = ($queryString = $asgiEnv['QUERY_STRING'])
            ? str_replace($queryString, '', $asgiEnv['REQUEST_URI'])
            : $asgiEnv['REQUEST_URI'];
        
        if (isset($this->endpoints[$requestUri])) {
            $this->attemptUpgrade($requestId, $requestUri, $asgiEnv);
        }
    }
    
    private function attemptUpgrade($requestId, $requestUri, array $asgiEnv) {
        $endpointConfig = $this->endpoints[$requestUri];
        $upgradeToken = $endpointConfig['upgradeToken'];
        $upgradeResponder = $endpointConfig['upgradeResponder'];
        
        $asgiResponse = $this->canUpgrade($upgradeToken, $asgiEnv)
            ? $this->invokeUpgradeResponder($upgradeResponder, $asgiEnv)
            : self::$upgradeRequired;
        
        $this->server->setResponse($requestId, $asgiResponse);
    }
    
    private function canUpgrade($upgradeToken, array $asgiEnv) {
        if ($asgiEnv['SERVER_PROTOCOL'] !== '1.1') {
            $canUpgrade = FALSE;
        } elseif ($asgiEnv['REQUEST_METHOD'] !== 'GET') {
            $canUpgrade = FALSE;
        } elseif (empty($asgiEnv['HTTP_CONNECTION'])
            || !$this->isConnectionHeaderValid($asgiEnv['HTTP_CONNECTION'])
        ) {
            $canUpgrade = FALSE;
        } elseif (empty($asgiEnv['HTTP_UPGRADE'])
            || strcasecmp($asgiEnv['HTTP_UPGRADE'], $upgradeToken)
        ) {
            $canUpgrade = FALSE;
            
        } else {
            $canUpgrade = TRUE;
        }
        
        return $canUpgrade;
    }
    
    private function isConnectionHeaderValid($header) {
        if (!strstr($header, ',')) {
            return !strcasecmp($header, 'Upgrade');
        }
        
        foreach (array_map('trim', explode(',', $header)) as $value) {
            if (!strcasecmp($value, 'Upgrade')) {
                return TRUE;
            }
        }
        
        return FALSE;
    }
    
    private function invokeUpgradeResponder(callable $upgradeResponder, array $asgiEnv) {
        try {
            $userCallbackResult = $upgradeResponder($asgiEnv);
            
            if (!$userCallbackResult) {
                $asgiResponse = self::$upgradeRequired;
            } elseif (is_callable($userCallbackResult)) {
                $asgiResponse = self::$switchingProtocols;
                $asgiResponse[4] = $userCallbackResult;
            } else {
                $asgiResponse = $userCallbackResult;
            }
            
            return $asgiResponse;
            
        } catch (\Exception $e) {
            return [
                $status = 500,
                $reason = 'Internal Server Error',
                $headers = ['Connection' => 'close', 'Content-Type' => 'text/html'],
                $body = "<html><body><h1>Internal Server Error</h1><pre>{$e}</pre></body></html>"
            ];
        }
    }
    
}

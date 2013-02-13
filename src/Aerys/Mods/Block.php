<?php

namespace Aerys\Mods;

use Aerys\Server,
    Aerys\Engine\EventBase;

/*
'mod.block' => [
    'ipProxyHeader' => NULL, // use the specified header instead of the raw IP if available
    'block' => ['*'], // specific IP or range of IPs
    'allow' => ['127.0.0.1'], // specific IP or range of IPs
],
*/

class Limit implements OnRequestMod {
    
    private $server;
    private $ipProxyHeader;
    private $block = [];
    private $allow = [];
    
    static function createMod(Server $server, EventBase $eventBase, array $config) {
        $class = __CLASS__;
        return new $class($server, $config);
    }
    
    function __construct(Server $server, array $config) {
        $this->server = $server;
        $this->configure($config);
    }
    
    private function configure(array $config) {
        if (!empty($config['block'])) {
            foreach ($config['block'] as $ip) {
                $this->block[$ip] = $ip;
            }
        }
        
        if (!empty($config['allow'])) {
            foreach ($config['allow'] as $ip) {
                $this->allow[$ip] = $ip;
            }
        }
    }
    
    /**
     * @param int $clientId
     * @param int $requestId
     * @return bool Returns FALSE on block/limiting so that onRequest mod processing will stop
     */
    function onRequest($clientId, $requestId) {
        $asgiEnv = $this->server->getRequest($requestId);
        
        if ($this->ipProxyHeader) {
            $clientIp = isset($asgiEnv[$this->ipProxyHeader])
                ? $asgiEnv[$this->ipProxyHeader]
                : $asgiEnv['REMOTE_ADDR'];
        } else {
            $clientIp = $asgiEnv['REMOTE_ADDR'];
        }
        
        if ($this->isBlocked($clientIp)) {
            $this->block($requestId);
        }
    }
    
    private function isBlocked($clientIp) {
        if (!($this->block || isset($this->block['*']))) {
            return FALSE;
        }
        
        if (!$isBlocked = isset($this->block['*'])) {
            foreach ($this->block as $blockedIp) {
                if (0 === strpos($clientIp, $blockedIp)) {
                    $isBlocked = TRUE;
                    break;
                }
            }
        }
        
        if (!$isBlocked || isset($this->allow['*'])) {
            return FALSE;
        }
        
        if (!$isAllowed = isset($this->allow[$peerName])) {
            foreach ($this->allow as $allowedIp) {
                if (0 === strpos($clientIp, $allowedIp)) {
                    return FALSE;
                }
            }
        }
        
        return $isBlocked;
    }
    
    private function block($requestId) {
        $body = "<html><body><h1>403 Forbidden</h1></body></html>";
        $headers = [
            'Content-Type' => 'text\html',
            'Content-Length' => strlen($body),
            'Connection' => 'close'
        ];
        $asgiResponse = [403, 'Forbidden', $headers, $body];
        
        $this->server->setResponse($requestId, $asgiResponse);
    }
    
}


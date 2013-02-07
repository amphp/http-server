<?php

namespace Aerys\Mods;

use Aerys\Server,
    Aerys\Engine\EventBase;

class Limit implements OnRequestMod {
    
    private $server;
    private $ipProxyHeader;
    private $perIpConnectionLimit = 16;
    private $rateLimits = [];
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
        if (!empty($config['ipProxyHeader'])) {
            $this->ipProxyHeader = $config['ipProxyHeader'];
        }
        
        if (!empty($config['perIpConnectionLimit'])) {
            $this->perIpConnectionLimit = (int) $config['perIpConnectionLimit'];
        }
        
        if (!empty($config['rateLimits'])) {
            $this->assignRateLimits($config['rateLimits']);
        }
        
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
    
    private function assignRateLimits(array $rateLimits) {
        // do something
    }
    
    function onRequest($clientId, $requestId) {
        if (!($this->block || isset($this->block['*']))) {
            return;
        }
        
        $asgiEnv = $this->server->getRequest($requestId);
        // @TODO Allow checking for a specific header
        $clientIp = $asgiEnv['REMOTE_ADDR'];
        
        if (!$isBlocked = isset($this->block['*'])) {
            foreach ($this->block as $blockedIp) {
                if (0 === strpos($clientIp, $blockedIp)) {
                    $isBlocked = TRUE;
                    break;
                }
            }
        }
        
        if (!$isBlocked || isset($this->allow['*'])) {
            return;
        }
        
        if (!$isAllowed = isset($this->allow[$peerName])) {
            foreach ($this->allow as $allowedIp) {
                if (0 === strpos($clientIp, $allowedIp)) {
                    $isAllowed = TRUE;
                    break;
                }
            }
        }
        
        if (!$isAllowed) {
            $this->forbid($requestId);
        }
    }
    
    private function forbid($clientSock) {
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


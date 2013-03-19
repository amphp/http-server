<?php

namespace Aerys\Mods;

use Aerys\Server;

/*
'mod.block' => [
    'ipProxyHeader' => NULL, // use the specified header instead of the raw IP if available
    'block' => ['*'], // specific IP or range of IPs
    'allow' => ['127.0.0.1'], // specific IP or range of IPs
],
*/

class Block implements OnRequestMod {
    
    private $ipProxyHeader;
    private $block = [];
    private $allow = [];
    
    function configure(array $config) {
        if (!empty($config['ipProxyHeader'])) {
            $this->ipProxyHeader = strtoupper($config['ipProxyHeader']);
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
    
    function onRequest(Server $server, $requestId) {
        $asgiEnv = $server->getRequest($requestId);
        
        if ($this->ipProxyHeader) {
            $clientIp = isset($asgiEnv[$this->ipProxyHeader])
                ? $asgiEnv[$this->ipProxyHeader]
                : $asgiEnv['REMOTE_ADDR'];
        } else {
            $clientIp = $asgiEnv['REMOTE_ADDR'];
        }
        
        if ($this->isBlocked($clientIp)) {
            $this->block($server, $requestId);
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
    
    private function block(Server $server, $requestId) {
        $body = "<html><body><h1>403 Forbidden</h1></body></html>";
        $headers = [
            'Content-Type' => 'text\html',
            'Content-Length' => strlen($body),
            'Connection' => 'close'
        ];
        $asgiResponse = [403, 'Forbidden', $headers, $body];
        
        $server->setResponse($requestId, $asgiResponse);
    }
    
}


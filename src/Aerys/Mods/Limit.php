<?php

namespace Aerys\Mods;

use Aerys\Server,
    Aerys\Engine\EventBase;

/*
'mod.limit' => [
    'ipProxyHeader' => NULL, // use the specified header instead of the raw IP if available
    'limits' => [
        60 => 100,
        3600 => 2500,
        86400 => 5000
    ]
],
*/

class Limit implements OnRequestMod {
    
    const RATE_LIMIT_CLEANUP_INTERVAL = 10000000;
    
    private $server;
    private $ipProxyHeader;
    private $rateLimits = [];
    private $ratePeriods = [];
    private $rateAllowances = [];
    private $lastRateCheckTimes = [];
    private $maxRatePeriod;
    
    static function createMod(Server $server, EventBase $eventBase, array $config) {
        $class = __CLASS__;
        return new $class($server, $eventBase, $config);
    }
    
    function __construct(Server $server, EventBase $eventBase, array $config) {
        $this->server = $server;
        $this->configure($config, $eventBase);
    }
    
    private function configure(array $config, EventBase $eventBase) {
        if (!empty($config['ipProxyHeader'])) {
            $this->ipProxyHeader = strtoupper($config['ipProxyHeader']);
        }
        
        ksort($config['limits']);
        $this->maxRatePeriod = max(array_keys($config['limits']));
        $this->rateLimits = $config['limits'];
        
        foreach (array_keys($config['limits']) as $period) {
            $this->ratePeriods[] = $period;
            $this->rateAllowances[$period] = [];
            $this->lastRateCheckTimes[$period] = [];
        }
        
        $eventBase->repeat(self::RATE_LIMIT_CLEANUP_INTERVAL, function() {
            $this->clearExpiredRateLimitData(time());
        });
        
    }
    
    /**
     * @param int $clientId
     * @param int $requestId
     * @return bool Returns FALSE if rate limited to prevent further onRequest mod processing
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
        
        $now = time();
        if ($this->isRateLimited($clientIp, $now)) {
            $this->limit($requestId, $clientIp);
        }
        
        $this->clearExpiredRateLimitData($now);
    }
    
    private function isRateLimited($clientIp, $now) {
        foreach ($this->rateLimits as $period => $rate) {
            if (!isset($this->rateAllowances[$period][$clientIp])) {
                $this->rateAllowances[$period][$clientIp] = $rate;
            }
            
            // NOTICE: We are operating on this value BY REFERENCE.
            $allowance =& $this->rateAllowances[$period][$clientIp];
            
            if (isset($this->lastRateCheckTimes[$clientIp])) {
                $elapsedTime = (float) ($now - $this->lastRateCheckTimes[$clientIp]);
                unset($this->lastRateCheckTimes[$clientIp]);
            } else {
                $elapsedTime = 0;
            }
            
            $this->lastRateCheckTimes[$clientIp] = $now;
            
            $allowance += $elapsedTime * ($rate / $period);
            
            // Throttle (because you can't save up "rate credit" beyond the max)
            if ($allowance > $rate) {
                $allowance = $rate;
            }
            
            // All requests, even rate-limited requests count against your average rate
            $allowance -= 1.0;
            if ($allowance < 0.0) {
                return TRUE;
            }
        }
        
        return FALSE;
    }
    
    private function limit($requestId, $clientIp) {
        $body = "<html><body><h1>429 Too Many Requests</h1><p>IP: $clientIp</p></body></html>";
        $headers = [
            'Content-Type' => 'text\html',
            'Content-Length' => strlen($body)
        ];
        $asgiResponse = [429, 'Too Many Requests', $headers, $body];
        
        $this->server->setResponse($requestId, $asgiResponse);
    }
    
    private function clearExpiredRateLimitData($now) {
        $cutoffTime = $now - $this->maxRatePeriod;
        
        foreach ($this->lastRateCheckTimes as $clientIp => $lastCheckedAt) {
            if ($lastCheckedAt < $cutoffTime) {
                unset($this->lastRateCheckTimes[$clientIp]);
                foreach ($this->ratePeriods as $period) {
                    unset($this->rateAllowances[$period][$clientIp]);
                }
            } else {
                break;
            }
        }
    }
    
}


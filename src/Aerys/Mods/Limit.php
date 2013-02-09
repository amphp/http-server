<?php

namespace Aerys\Mods;

use Aerys\Server,
    Aerys\Engine\EventBase;

/*
'mod.limit' => [
    'ipProxyHeader' => NULL, // use the specified header instead of the raw IP if available
    'onLimitCmd' => NULL,
    'onLimitCallback' => NULL,
    'limits' => [
        60 => 100,
        3600 => 2500
    ]
],
*/

class Limit implements OnRequestMod {

    private $server;
    
    private $ipProxyHeader;
    private $rateLimits = [];
    private $ratePeriods = [];
    private $rateAllowances = [];
    private $lastRateCheckedAt = [];
    private $maxRatePeriod;
    
    private $onLimitCmd;
    private $onLimitCallback;
    
    static function createMod(Server $server, EventBase $eventBase, array $config) {
        $class = __CLASS__;
        return new $class($server,$config);
    }
    
    function __construct(Server $server, array $config) {
        $this->server = $server;
        $this->configure($config);
    }
    
    private function configure(array $config) {
        if (!empty($config['ipProxyHeader'])) {
            $this->ipProxyHeader = strtoupper($config['ipProxyHeader']);
        }
        if (!empty($config['onLimitCmd'])) {
            $this->onLimitCmd = $config['onLimitCmd'];
        }
        if (!empty($config['onLimitCallback']) && is_callable($config['onLimitCallback'])) {
            $this->onLimitCallback = $config['onLimitCallback'];
        }
        if (empty($config['limits'])) {
            throw new \DomainException(
                'No rate limits specified'
            );
        }
        
        ksort($config['limits']);
        $this->maxRatePeriod = max(array_keys($config['limits']));
        $this->rateLimits = $config['limits'];
        $this->ratePeriods[] = array_keys($config['limits']);
    }
    
    /**
     * @param int $clientId
     * @param int $requestId
     * @return void
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
            $this->limit($requestId);
            if ($this->onLimitCmd) {
                $this->execSystemCmd($clientIp);
            }
            if ($callback = $this->onLimitCallback) {
                try {
                    $callback($clientIp);
                } catch (\Exception $e) {
                    // @TODO Handle exception
                }
            }
        }
        
        $this->clearExpiredRateLimitData($now);
    }
    
    private function isRateLimited($clientIp, $now) {
        if (isset($this->lastRateCheckedAt[$clientIp])) {
            $elapsedTime = $now - $this->lastRateCheckedAt[$clientIp];
            // move the clientIp check timestamp to the end of the ordered array for timed removal
            unset($this->lastRateCheckedAt[$clientIp]);
        } else {
            $elapsedTime = 0;
            $this->rateAllowances[$clientIp] = $this->rateLimits;
        }
        
        $this->lastRateCheckedAt[$clientIp] = $now;
        
        // NOTICE: We are operating on this value BY REFERENCE.
        $allowances =& $this->rateAllowances[$clientIp];
        
        foreach ($this->rateLimits as $period => $rate) {
            $allowances[$period] += $elapsedTime * ($rate / $period);
            
            // Throttle (because you can't save up "rate credit" beyond the max)
            if ($allowances[$period] > $rate) {
                $allowances[$period] = $rate;
            }
            
            // All requests (even rate-limited requests) count against your average rate
            $allowances[$period] -= 1.0;
            
            if ($allowances[$period] < 0.0) {
                return TRUE;
            }
        }
        
        return FALSE;
    }
    
    private function limit($requestId) {
        $body = "<html><body><h1>429 Too Many Requests</h1></body></html>";
        $headers = [
            'Content-Type' => 'text\html',
            'Content-Length' => strlen($body)
        ];
        $asgiResponse = [429, 'Too Many Requests', $headers, $body];
        
        $this->server->setResponse($requestId, $asgiResponse);
    }
    
    private function execSystemCmd($clientIp) {
        $cmd = escapeshellcmd($this->onLimitCmd . ' ' . escapeshellarg($clientIp));
        
        if (substr(php_uname(), 0, 7) == "Windows") {
            pclose(popen("start /B ". $cmd, "r"));
        } else {
            exec($cmd . " > /dev/null &");
        }
    }
    
    private function clearExpiredRateLimitData($now) {
        $cutoffTime = $now - $this->maxRatePeriod;
        
        foreach ($this->lastRateCheckedAt as $clientIp => $lastCheckedAt) {
            if ($lastCheckedAt < $cutoffTime) {
                unset(
                    $this->lastRateCheckedAt[$clientIp],
                    $this->rateAllowances[$clientIp]
                );
            } else {
                break;
            }
        }
    }
    
}


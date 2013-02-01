<?php

namespace Aerys\Mods;

use Aerys\Server;

class Log implements OnResponseMod {
    
    private $server;
    private $resources = [];
    private $resourceFormatMap = [];
    private $buffers = [];
    private $msgGenerationCache = [];
    private $flushSize = 1024;
    private $logTime;
    
    function __construct(Server $server) {
        $this->server = $server;
    }
    
    function configure(array $config) {
        if (isset($config['flushSize'])) {
            $this->flushSize = (int) $config['flushSize'];
        }
        
        foreach ($config['logs'] as $path => $format) {
            if (!$resource = fopen($path, 'a+')) {
                continue;
            }
            
            stream_set_blocking($resource, FALSE);
            
            $resourceId = (int) $resource;
            $this->buffers[$resourceId] = ['', 0];
            $this->resources[$resourceId] = $resource;
            $this->resourceFormatMap[$resourceId] = $format;
        }
    }
    
    function __destruct() {
        foreach ($this->buffers as $resourceId => $bufferList) {
            $buffer = $bufflerList[0];
            
            if (!$buffer && $buffer !== '0') {
                continue;
            }
            
            $resource = $this->resources[$resourceId];
            stream_set_blocking($resource, TRUE);
            fwrite($resource, $buffer);
            fclose($resource);
        }
    }
    
    function onResponse($clientId, $requestId) {
        $this->logTime = time();
        
        foreach ($this->resources as $resourceId => $resource) {
            $format = $this->resourceFormatMap[$resourceId];
            
            switch ($format) {
                case 'combined':
                    $msg = $this->doCombinedFormat($requestId);
                    break;
                case 'common':
                    $msg = $this->doCommonFormat($requestId);
                    break;
                default:
                    $msg = $this->doCustomFormat($requestId, $format);
            }
            
            $buffer = $this->buffers[$resourceId][0] . $msg;
            $bufferSize = strlen($buffer);
            
            if ($bufferSize > $this->flushSize) {
                $bytesWritten = fwrite($resource, $buffer);
                $this->buffers[$resourceId][0] = substr($buffer, $bytesWritten);
                $this->buffers[$resourceId][1] -= $bytesWritten;
            }
        }
        
        $this->msgGenerationCache = [];
    }
    
    /**
     * @link http://httpd.apache.org/docs/2.2/logs.html#combined
     */
    private function doCombinedFormat($requestId) {
        if (isset($this->msgGenerationCache['combined'])) {
            return $this->msgGenerationCache['combined'];
        }
        
        $asgiEnv = $this->server->getRequest($requestId);
        $asgiResponse = $this->server->getResponse($requestId);
        
        $ip = $asgiEnv['REMOTE_ADDR'];
        
        $requestLine = '"' . $asgiEnv['REQUEST_METHOD'] . ' ';
        $requestLine.= $asgiEnv['REQUEST_URI'] . ' ';
        $requestLine.= 'HTTP/' . $asgiEnv['SERVER_PROTOCOL'] . '"';
        
        $statusCode = $asgiResponse[0];
        $bodySize = empty($asgiResponse[1]['CONTENT-LENGTH']) ? '-' : $asgiResponse[1]['CONTENT-LENGTH'];
        $referer = empty($asgiEnv['HTTP_REFERER']) ? '-' : $asgiEnv['HTTP_REFERER'];
        
        $userAgent = empty($asgiEnv['HTTP_USER_AGENT']) ? '-' : '"' . $asgiEnv['HTTP_USER_AGENT'] . '"';
        $time = date('d/M/Y:H:i:s O', $this->logTime);
        
        // $ip $identd $userId $time $requestLine $statusCode $bytesReturned $referer $userAgent
        $msg = "$ip - - [$time] $requestLine $statusCode $bodySize $referer $userAgent" . PHP_EOL;
        
        $this->msgGenerationCache['combined'] = $msg;
        
        return $msg;
    }
    
    /**
     * @link http://httpd.apache.org/docs/2.2/logs.html#common
     */
    private function doCommonFormat($requestId) {
        if (isset($this->msgGenerationCache['common'])) {
            return $this->msgGenerationCache['common'];
        }
        
        $asgiEnv = $this->server->getRequest($requestId);
        $asgiResponse = $this->server->getResponse($requestId);
        
        $ip = $asgiEnv['REMOTE_ADDR'];
        
        $requestLine = '"' . $asgiEnv['REQUEST_METHOD'] . ' ';
        $requestLine.= $asgiEnv['REQUEST_URI'] . ' ';
        $requestLine.= 'HTTP/' . $asgiEnv['SERVER_PROTOCOL'] . '"';
        
        $statusCode = $asgiResponse[0];
        $bodySize = empty($asgiResponse[1]['CONTENT-LENGTH']) ? '-' : $asgiResponse[1]['CONTENT-LENGTH'];
        $time = date('d/M/Y:H:i:s O', $this->logTime);
        
        // $ip $identd $userId $time $requestLine $statusCode $bytesReturned
        $msg = "$ip - - [$time] $requestLine $statusCode $bodySize" . PHP_EOL;
        
        $this->msgGenerationCache['common'] = $msg;
        
        return $msg;
    }
    
    private function doCustomFormat($requestId, $format) {
        $asgiEnv = $this->server->getRequest($requestId);
        $asgiResponse = $this->server->getResponse($requestId);
        
        $requestLine = '"' . $asgiEnv['REQUEST_METHOD'] . ' ';
        $requestLine.= $asgiEnv['REQUEST_URI'] . ' ';
        $requestLine.= 'HTTP/' . $asgiEnv['SERVER_PROTOCOL'] . '"';
        
        $bytes = empty($asgiResponse[1]['CONTENT-LENGTH']) ? '-' : $asgiResponse[1]['CONTENT-LENGTH'];
        
        $search = ['%h', '%t', '%r', '%s', '%b'];
        $replace = [
            $asgiEnv['REMOTE_ADDR'],
            date('d/M/Y:H:i:s O', $this->logTime),
            $requestLine,
            $asgiResponse[0],
            $bytes
        ];
        
        $msg = str_replace($search, $replace, $format);
        
        
        $pattern = "/\${([^\)]+)}/";
        if (!preg_match_all($pattern, $msg, $matches), PREG_SET_ORDER) {
            return $msg;
        }
        
        foreach ($matches as $match) {
            print_r($match[1]);
        }
        
        return $msg;
    }
    
}



























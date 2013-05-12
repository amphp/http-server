<?php

namespace Aerys\Mods\Log;

use Aerys\Server,
    Aerys\Mods\AfterResponseMod;

class ModLog implements AfterResponseMod {
    
    private $server;
    private $resources = [];
    private $resourceFormatMap = [];
    private $buffers = [];
    private $flushSize = 0;
    private $afterResponsePriority = 75;
    
    function __construct(Server $server, array $logs) {
        $this->server = $server;
        if ($logs) {
            $this->setLogs($logs);
        } else {
            throw new \InvalidArgumentException(
                __CLASS__ . '::__construct expects a non-empty $logs array at Argument 2'
            );
        }
    }
    
    private function setLogs(array $logs) {
        foreach ($logs as $path => $format) {
            if ($resource = $this->openLogResource($path)) {
                stream_set_blocking($resource, FALSE);
                $resourceId = (int) $resource;
                $this->buffers[$resourceId] = ['', 0];
                $this->resources[$resourceId] = $resource;
                $this->resourceFormatMap[$resourceId] = $format;
            }
        }
    }
    
    private function openLogResource($path) {
        return ($path[0] === '|') ? popen(trim(substr($path, 1)), 'w') : fopen($path, 'a+');
    }
    
    function setFlushSize($bytes) {
        $this->flushSize = filter_var($bytes, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'default' => 0
        ]]);
    }
    
    function getAfterResponsePriority() {
        return $this->afterResponsePriority;
    }
    
    function afterResponse($requestId) {
        foreach ($this->resources as $resourceId => $resource) {
            $asgiEnv = $this->server->getRequest($requestId);
            $asgiResponse = $this->server->getResponse($requestId);
            $format = $this->resourceFormatMap[$resourceId];
            
            switch ($format) {
                case 'combined':
                    $msg = $this->doCombinedFormat($asgiEnv, $asgiResponse);
                    break;
                case 'common':
                    $msg = $this->doCommonFormat($asgiEnv, $asgiResponse);
                    break;
                default:
                    $msg = $this->doCustomFormat($asgiEnv, $asgiResponse, $format);
            }
            
            $buffer = $this->buffers[$resourceId][0] . $msg;
            $bufferSize = strlen($buffer);
            
            if ($this->flushSize && $bufferSize < $this->flushSize) {
                continue;
            }
            
            $bytesWritten = @fwrite($resource, $buffer);
            
            if ($bytesWritten === $bufferSize) {
                $this->buffers[$resourceId][0] = '';
                $this->buffers[$resourceId][1] = 0;
            } else {
                $this->buffers[$resourceId][0] = substr($buffer, $bytesWritten);
                $this->buffers[$resourceId][1] -= $bytesWritten;
            }
        }
    }
    
    /**
     * @link http://httpd.apache.org/docs/2.2/logs.html#combined
     */
    private function doCombinedFormat(array $asgiEnv, array $asgiResponse) {
        $ip = $asgiEnv['REMOTE_ADDR'];
        
        $requestLine = '"' . $asgiEnv['REQUEST_METHOD'] . ' ';
        $requestLine.= $asgiEnv['REQUEST_URI'] . ' ';
        $requestLine.= 'HTTP/' . $asgiEnv['SERVER_PROTOCOL'] . '"';
        
        $statusCode = $asgiResponse[0];
        $headers = $asgiResponse[2];
        $bodySize = empty($headers['CONTENT-LENGTH']) ? '-' : $headers['CONTENT-LENGTH'];
        $referer = empty($asgiEnv['HTTP_REFERER']) ? '-' : $asgiEnv['HTTP_REFERER'];
        
        $userAgent = empty($asgiEnv['HTTP_USER_AGENT']) ? '-' : '"' . $asgiEnv['HTTP_USER_AGENT'] . '"';
        $time = date('d/M/Y:H:i:s O');
        
        $msg = "$ip - - [$time] $requestLine $statusCode $bodySize $referer $userAgent" . PHP_EOL;
        
        return $msg;
    }
    
    /**
     * @link http://httpd.apache.org/docs/2.2/logs.html#common
     */
    private function doCommonFormat(array $asgiEnv, array $asgiResponse) {
        $ip = $asgiEnv['REMOTE_ADDR'];
        
        $requestLine = '"' . $asgiEnv['REQUEST_METHOD'] . ' ';
        $requestLine.= $asgiEnv['REQUEST_URI'] . ' ';
        $requestLine.= 'HTTP/' . $asgiEnv['SERVER_PROTOCOL'] . '"';
        
        $statusCode = $asgiResponse[0];
        $headers = $asgiResponse[2];
        $bodySize = empty($headers['CONTENT-LENGTH']) ? '-' : $headers['CONTENT-LENGTH'];
        $time = date('d/M/Y:H:i:s O');
        
        $msg = "$ip - - [$time] $requestLine $statusCode $bodySize" . PHP_EOL;
        
        return $msg;
    }
    
    /**
     * %h - Remote IP Address
     * %t - Log Time
     * %r - Request Line
     * $s - Response Status Code
     * %b - Response Content-Length in bytes ("-" if not available)
     * 
     * %{HEADER-FIELD} - Any request header (case-insensitive, "-" if not available)
     */
    private function doCustomFormat(array $asgiEnv, array $asgiResponse, $format) {
        $requestLine = '"' . $asgiEnv['REQUEST_METHOD'] . ' ';
        $requestLine.= $asgiEnv['REQUEST_URI'] . ' ';
        $requestLine.= 'HTTP/' . $asgiEnv['SERVER_PROTOCOL'] . '"';
        
        $headers = $asgiResponse[2];
        
        $bytes = empty($headers['CONTENT-LENGTH']) ? '-' : $headers['CONTENT-LENGTH'];
        
        $search = ['%h', '%t', '%r', '%s', '%b'];
        $replace = [
            $asgiEnv['REMOTE_ADDR'],
            date('d/M/Y:H:i:s O'),
            $requestLine,
            $asgiResponse[0],
            $bytes
        ];
        
        $msg = str_replace($search, $replace, $format);
        
        if (FALSE === strpos($msg, '%{')) {
            return $msg . PHP_EOL;
        } else {
            $replace = function ($match) use ($asgiEnv) {
                $match = 'HTTP_' . str_replace('-', '_', strtoupper($match[1]));
                return isset($asgiEnv[$match]) ? $asgiEnv[$match] : '-';
            };
            
            return preg_replace_callback("/\%{([^\)]+)}/U", $replace, $msg) . PHP_EOL;
        }
    }
    
    function __destruct() {
        foreach ($this->buffers as $resourceId => $bufferList) {
            $buffer = $bufferList[0];
            $resource = $this->resources[$resourceId];
            
            if ($buffer || $buffer === '0') {
                stream_set_blocking($resource, TRUE);
                fwrite($resource, $buffer);
            }
            
            fclose($resource);
        }
    }
}


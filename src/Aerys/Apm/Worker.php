<?php

namespace Aerys\Apm;

use Aerys\Engine\EventBase;

class Worker {
    
    const READ_TIMEOUT = 60000000;
    
    private $parser;
    private $process;
    private $pipes = [];
    private static $descriptors = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];
    
    private $readSubscription;
    
    function __construct(EventBase $eventBase, MessageParser $parser, $cmd, $workingDir = NULL) {
        $workingDir = $workingDir ?: getcwd();
        $this->process = proc_open($cmd, self::$descriptors, $this->pipes, $workingDir);
        
        if (!is_resource($this->process)) {
            throw new \RuntimeException(
                'Failed spawning APM child process'
            );
        }
        $this->parser = $parser;
        
        stream_set_blocking($this->pipes[1], FALSE);
        
        $this->readSubscription = $eventBase->onReadable(
            $this->pipes[1],
            [$this, 'read'],
            self::READ_TIMEOUT
        );
    }
    
    function read($readPipe, $triggeredBy) {
        if ($triggeredBy == EV_TIMEOUT) {
            return;
        }
        
        $input = fread($readPipe, 8192);
        
        if ($input || $input === '0') {
            return $this->parser->parse($input);
        } elseif (is_resource($readPipe)) {
            return;
        }
        
        // If we're still here it means the pipe has died
        if ($onError = $this->onError) {
            $onError($this);
        } else {
            throw new \RuntimeException(
                'Worker pipe READ failure'
            );
        }
    }
    
    function write($msg) {
        fwrite($this->pipes[0], $msg);
    }
    
    function __destruct() {
        $this->readSubscription->cancel();
        
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        
        proc_close($this->process);
    }
    
}


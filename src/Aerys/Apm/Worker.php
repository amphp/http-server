<?php

namespace Aerys\Apm;

use Aerys\Reactor\Reactor;

class Worker {
    
    const READ_TIMEOUT = 60000000;
    
    private $parser;
    private $process;
    private $errorOutputStream;
    private $pipes = [];
    private static $descriptors = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];
    
    private $readSubscription;
    private $errorSubscription;
    
    function __construct(
        Reactor $eventBase,
        MessageParser $parser,
        $errorOutputStream,
        $cmd,
        $workingDir = NULL
    ) {
        $workingDir = $workingDir ?: getcwd();
        $this->process = proc_open($cmd, self::$descriptors, $this->pipes, $workingDir);
        
        if (!is_resource($this->process)) {
            throw new \RuntimeException(
                'Failed spawning APM child process'
            );
        }
        $this->parser = $parser;
        $this->errorOutputStream = $errorOutputStream;
        
        stream_set_blocking($this->pipes[0], FALSE);
        stream_set_blocking($this->pipes[1], FALSE);
        stream_set_blocking($this->pipes[2], FALSE);
        
        $this->readSubscription = $eventBase->onReadable($this->pipes[1], [$this, 'read'], self::READ_TIMEOUT);
        $this->errorSubscription = $eventBase->onReadable($this->pipes[2], [$this, 'error'], self::READ_TIMEOUT);
    }
    
    function error($errorPipe, $triggeredBy) {
        if ($triggeredBy != Reactor::TIMEOUT) {
            stream_copy_to_stream($errorPipe, $this->errorOutputStream);
        }
    }
    
    function read($readPipe, $triggeredBy) {
        if ($triggeredBy == Reactor::TIMEOUT) {
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
        $this->errorSubscription->cancel();
        
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        
        proc_close($this->process);
    }
    
}


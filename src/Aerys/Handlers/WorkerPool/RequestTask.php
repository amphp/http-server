<?php

namespace Aerys\Handlers\WorkerPool;

use Amp\Async\IncrementalDispatchable;

class RequestTask implements IncrementalDispatchable {
    
    const PROCEDURE_PLACEHOLDER = '.';
    
    private $handler;
    private $jsonAsgiEnv;
    
    function __construct(Handler $handler, array $asgiEnv) {
        $this->handler = $handler;
        $this->jsonAsgiEnv = json_encode($asgiEnv);
    }
    
    function getProcedure() {
        return self::PROCEDURE_PLACEHOLDER;
    }
    
    function getWorkload() {
        return $this->jsonAsgiEnv;
    }
    
    function onIncrement($partialResult, $callId) {
        $this->handler->onIncrement($partialResult, $callId);
    }
    
    function onSuccess($result, $callId) {
        $this->handler->onSuccess($result, $callId);
    }
    
    function onError(\Exception $e, $callId) {
        $this->handler->onError($e, $callId);
    }
    
}


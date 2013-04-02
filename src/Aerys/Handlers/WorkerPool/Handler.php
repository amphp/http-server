<?php

namespace Aerys\Handlers\WorkerPool;

use Aerys\Server,
    Aerys\Status,
    Aerys\Reason,
    Amp\Async\Dispatcher,
    Amp\Async\CallResult;

class Handler {
    
    private $server;
    private $dispatcher;
    private $procedure = 'main';
    private $callIdRequestIdMap = [];
    private $streamBodyMap = [];
    
    function __construct(Server $server, Dispatcher $dispatcher) {
        $this->server = $server;
        $this->dispatcher = $dispatcher;
    }
    
    function setWorkerProcedure($procedureName) {
        $this->procedure = $procedureName;
    }
    
    function __invoke(array $asgiEnv, $requestId) {
        $asgiEnv = $this->normalizeStreamsForTransport($asgiEnv);
        $workload = json_encode($asgiEnv);
        $callId = $this->dispatcher->call([$this, 'onResult'], $this->procedure, $workload);
        
        $this->callIdRequestIdMap[$callId] = $requestId;
    }
    
    private function normalizeStreamsForTransport(array $asgiEnv) {
        // External processes can't access the entity body stream before completion or everything 
        // goes to hell. On completion we change the input stream value to its temp filesystem
        // path so worker processes can load up the input stream as a file handle on their own.
        
        $hasEntity = (bool) $asgiEnv['ASGI_INPUT'];
        $isEntityFullyRcvd = $asgiEnv['ASGI_LAST_CHANCE'];
        
        if ($hasEntity && $isEntityFullyRcvd) {
            $asgiEnv['ASGI_INPUT'] = stream_get_meta_data($asgiEnv['ASGI_INPUT'])['uri'];
        } elseif (!$isEntityFullyRcvd && $hasEntity) {
            $asgiEnv['ASGI_INPUT'] = NULL;
        }
        
        // We can't pass the error stream across processes. Instead the worker MUST populate this
        // value with its own STDERR resource so that error messages will be automatically written
        // to the current process's STDERR (or assigned error log) by the process pool dispatcher.
        unset($asgiEnv['ASGI_ERROR']);
        
        return $asgiEnv;
    }
    
    function onResult(CallResult $callResult) {
        if (!$callResult->isComplete()) {
            $this->onIncrement($callResult);
        } elseif ($callResult->isSuccess()) {
            $this->onSuccess($callResult);
        } else {
            $this->onError($callResult);
        }
    }
    
    private function onIncrement(CallResult $callResult) {
        $callId = $callResult->getCallId();
        $partialResult = $callResult->getResult();
        
        if (isset($this->streamBodyMap[$callId])) {
            $this->streamBodyMap[$callId]->addData($partialResult);
        } else {
            $this->onFirstStreamIncrement($callId, $partialResult);
        }
    }
    
    private function onFirstStreamIncrement($callId, $jsonAsgiResponse) {
        $requestId = $this->callIdRequestIdMap[$callId];
        unset($this->callIdRequestIdMap[$callId]);
        
        $streamIterator = new StreamResponseBody;
        
        $asgiResponse = json_decode($jsonAsgiResponse, TRUE);
        $asgiResponse[3] = $streamIterator;
        $this->streamBodyMap[$callId] = $streamIterator;
        
        $this->server->setResponse($requestId, $asgiResponse);
    }
    
    private function onSuccess(CallResult $callResult) {
        $callId = $callResult->getCallId();
        
        if (isset($this->streamBodyMap[$callId])) {
            $this->streamBodyMap[$callId]->addData($callResult->getResult());
            $this->streamBodyMap[$callId]->markComplete();
        } else {
            $requestId = $this->callIdRequestIdMap[$callId];
            unset($this->callIdRequestIdMap[$callId]);
            $asgiResponse = json_decode($callResult->getResult(), TRUE);
            
            $this->server->setResponse($requestId, $asgiResponse);
        }
    }
    
    private function onError(CallResult $callResult) {
        $callId = $callResult->getCallId();
        $error = $callResult->getError();
        $requestId = $this->callIdRequestIdMap[$callId];
        
        unset($this->callIdRequestIdMap[$callId]);
        
        if (isset($this->streamBodyMap[$callId])) {
            
            // @TODO Handle errors occuring during body stream processing
            // @TODO Close the socket for 1.0, send final chunk for 1.1 if response output started
            // @TODO Set 500 response if response output not yet started
            unset($this->streamBodyMap[$callId]);
            
        } else {
            $this->server->setResponse($requestId, [
                $status  = Status::INTERNAL_SERVER_ERROR,
                $reason  = Reason::HTTP_500,
                $headers = [],
                $body    = $error
            ]);
        }
    }
    
}


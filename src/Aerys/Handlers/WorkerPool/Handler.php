<?php

namespace Aerys\Handlers\WorkerPool;

use Aerys\Server,
    Aerys\Status,
    Aerys\Reason,
    Amp\Async\Processes\ProcessDispatcher;

class Handler {
    
    private $server;
    private $dispatcher;
    private $callIdRequestIdMap = [];
    private $streamBodyMap = [];
    
    function __construct(Server $server, ProcessDispatcher $dispatcher) {
        $this->server = $server;
        $this->dispatcher = $dispatcher;
    }
    
    function __invoke(array $asgiEnv, $requestId) {
        $asgiEnv = $this->normalizeStreamsForTransport($asgiEnv);
        $task = new RequestTask($this, $asgiEnv);
        $callId = $this->dispatcher->call($task);
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
    
    function onIncrement($partialResult, $callId) {
        if (isset($this->streamBodyMap[$callId])) {
            $this->streamBodyMap[$callId]->addData($partialResult);
        } else {
            $this->onFirstStreamIncrement($partialResult, $callId);
        }
    }
    
    function onSuccess($result, $callId) {
        if (isset($this->streamBodyMap[$callId])) {
            $this->streamBodyMap[$callId]->markComplete();
        } else {
            $requestId = $this->callIdRequestIdMap[$callId];
            unset($this->callIdRequestIdMap[$callId]);
            
            $asgiResponse = json_decode($result, TRUE);
            
            $this->server->setResponse($requestId, $asgiResponse);
        }
    }
    
    function onError(\Exception $e, $callId) {
        $requestId = $this->callIdRequestIdMap[$callId];
        unset($this->callIdRequestIdMap[$callId]);
        
        if (isset($this->streamBodyMap[$callId])) {
            // @TODO Handle errors occuring during body stream processing
            // @TODO Close the socket for 1.0, send final chunk for 1.1 if response output started
            // @TODO Set 500 response if response output not yet started
        } else {
            $this->server->setResponse($requestId, [
                $status  = Status::INTERNAL_SERVER_ERROR,
                $reason  = Reason::HTTP_500,
                $headers = [],
                $body    = $e
            ]);
        }
    }
    
    private function onFirstStreamIncrement($jsonAsgiResponse, $callId) {
        $requestId = $this->callIdRequestIdMap[$callId];
        unset($this->callIdRequestIdMap[$callId]);
        
        $streamIterator = new StreamResponseBody;
        
        $asgiResponse = json_decode($jsonAsgiResponse, TRUE);
        $asgiResponse[3] = $streamIterator;
        $this->streamBodyMap[$callId] = $streamIterator;
        
        $this->server->setResponse($requestId, $asgiResponse);
    }
}


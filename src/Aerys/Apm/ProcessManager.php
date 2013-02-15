<?php

namespace Aerys\Apm;

use Aerys\Server,
    Aerys\Engine\EventBase,
    Aerys\InitHandler;

class ProcessManager implements InitHandler {
    
    const APM_VERSION = 1;
    
    private $cmd;
    private $childWorkingDir;
    private $maxWorkers = 20;
    
    private $workers = [];
    private $workerIdMap;
    private $pendingRequestCounts = [];
    private $requestIdWorkerMap = [];
    
    function __construct($command, $workingDir = NULL) {
        $this->cmd = $command;
        $this->childWorkingDir = $workingDir ?: getcwd();
        $this->workerIdMap = new \SplObjectStorage;
    }
    
    function init(Server $server, EventBase $eventBase) {
        $this->server = $server;
        $this->eventBase = $eventBase;
        
        for ($i=0; $i < $this->maxWorkers; $i++) {
            $this->spawnWorker();
        }
    }
    
    function setMaxWorkers($workers) {
        $this->maxWorkers = (int) $workers;
    }
    
    function __invoke(array $asgiEnv, $requestId) {
        // Assign the worker with the fewest pending requests
        asort($this->pendingRequestCounts);
        $workerId = key($this->pendingRequestCounts);
        
        /*
        // Round-robin requests to each worker
        if (NULL === ($workerId = key($this->workers))) {
            reset($this->workers);
            $workerId = key($this->workers);
        }
        next($this->workers);
        */
        
        /*
        // Assign a worker at random
        $workerId = array_rand($this->workers);
        */
        
        $worker = $this->workers[$workerId];
        $this->requestIdWorkerMap[$requestId] = $workerId;
        ++$this->pendingRequestCounts[$workerId];
        
        $asgiEnv = $this->normalizeStreamsForTransport($asgiEnv);
        
        $body = json_encode($asgiEnv);
        $msg = pack(
            Message::HEADER_PACK_PATTERN,
            self::APM_VERSION,
            Message::REQUEST,
            $requestId,
            strlen($body)
        ) . $body;
        
        $worker->write($msg);
    }
    
    private function normalizeStreamsForTransport(array $asgiEnv) {
        // External processes can't access the entity body stream before completion or everything 
        // goes to hell. On completion we change the input stream value to its temp filesystem
        // path so worker processes can load up the input stream as a file handle on their own.
        if ($asgiEnv['ASGI_LAST_CHANCE'] && $asgiEnv['ASGI_INPUT']) {
            $asgiEnv['ASGI_INPUT'] = stream_get_meta_data($asgiEnv['ASGI_INPUT'])['uri'];
        } elseif (!$asgiEnv['ASGI_LAST_CHANCE'] && $asgiEnv['ASGI_INPUT']) {
            $asgiEnv['ASGI_INPUT'] = NULL;
        }
        
        // We can't pass the error stream across processes. Instead the worker MUST populate this
        // value with its own STDERR resource so that error messages are returned to the current
        // process.
        unset($asgiEnv['ASGI_ERROR']);
        
        return $asgiEnv;
    }
    
    private function spawnWorker() {
        $parser = (new MessageParser)->setOnMessageCallback(function(array $msg) {
            $this->onResponse($msg);
        });
        
        $worker = new Worker($this->eventBase, $parser, $this->cmd, $this->childWorkingDir);
        $this->workers[] = $worker;
        end($this->workers);
        $workerId = key($this->workers);
        $this->pendingRequestCounts[$workerId] = 0;
        $this->workerIdMap->attach($worker, $workerId);
    }
    
    private function onResponse(array $msg) {
        list($type, $requestId, $asgiResponse) = $msg;
        
        $asgiResponse = $asgiResponse ? json_decode($asgiResponse, TRUE) : $asgiResponse;
        $workerId = $this->requestIdWorkerMap[$requestId];
        
        --$this->pendingRequestCounts[$workerId];
        unset($this->requestIdWorkerMap[$requestId]);
        
        if (NULL !== $asgiResponse) {
            $this->server->setResponse($requestId, $asgiResponse);
        }
    }
    
    private function onWorkerError(Worker $worker) {
        /*
        $this->deadWorkerGarbageBin[] = $worker;
        
        $workerId = $this->workerIdMap->offsetGet($worker);
        $requestId = $msg->getRequestId();
        
        unset(
            $this->requestIdWorkerMap[$requestId],
            $this->workers[$workerId]
        );
        
        $this->spawnWorker();
        
        $this->eventBase->once(1000000, function() {
            $this->deadWorkerGarbageBin = [];
        });
        */
    }
    
}


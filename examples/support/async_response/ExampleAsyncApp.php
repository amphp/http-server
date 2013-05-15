<?php

use Aerys\Server,
    Amp\Async\PhpDispatcher,
    Amp\Async\CallResult;

class ExampleAsyncApp {
    
    private $server;
    private $dispatcher;
    private $callIdRequestMap = [];
    
    /**
     * These dependencies are automatically provisioned by the Aerys Configurator using the
     * PhpDispatcher instance we shared in the main example file. The actual Server instance
     * is created by the Configurator and shared during the bootstrap phase.
     */
    function __construct(Server $server, PhpDispatcher $dispatcher) {
        $this->server = $server;
        $this->dispatcher = $dispatcher;
    }
    
    /**
     * Dispatch an asynchronous function call whose return value will be used to assign an
     * appropriate response with the server when it completes.
     */
    function __invoke(array $asgiEnv, $requestId) {
        $onResult = [$this, 'onCallResult'];
        $callId = $this->dispatcher->call($onResult, 'some_slow_io_function', 'Zanzibar!');
        $this->callIdRequestMap[$callId] = $requestId;
        
        // Don't return a response now because we won't know how
        // to respond appropriately until our async function returns.
    }
    
    /**
     * Send an appropriate response back to the server when the async call returns.
     */
    function onCallResult(CallResult $result) {
        $callId = $result->getCallId();
        $requestId = $this->callIdRequestMap[$callId];
        unset($this->callIdRequestMap[$callId]);
        
        if ($result->isSuccess()) {
            $body = '<html><body><h1>Async FTW!</h1><p>' . $result->getResult() . '</p></body></html>';
            $asgiResponse = [200, 'OK', $headers = [], $body];
        } else {
            $body = '<html><body><h1>Doh!</h1><pre>'. $result->getError() .'</pre></body></html>';
            $asgiResponse = [500, 'Internal Server Error', $headers = [], $body];
        }
        
        $this->server->setResponse($requestId, $asgiResponse);
    }
    
}


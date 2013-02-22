<?php

namespace Aerys\Pipeline;

class Session {
    
    protected $reader;
    protected $writer;
    protected $requests = [];
    protected $responses = [];
    protected $isWriting = [];
    
    function __construct(Reader $reader, Writer $writer) {
        $this->reader = $reader;
        $this->writer = $writer;
    }
    
    function isEmpty() {
        return !$this->requests;
    }
    
    function canWrite() {
        return (bool) $this->responses;
    }
    
    function hasRequest($requestId) {
        return isset($this->requests[$requestId]);
    }
    
    function getRequest($requestId) {
        return $this->requests[$requestId];
    }
    
    function setRequest($requestId, $request) {
        $this->requests[$requestId] = $request;
        $this->isWriting[$requestId] = FALSE;
    }
    
    function hasResponse($requestId) {
        return isset($this->responses[$requestId]);
    }
    
    function getResponse($requestId) {
        return $this->responses[$requestId];
    }
    
    function setResponse($requestId, $asgiResponse) {
        $this->responses[$requestId] = $asgiResponse;
    }
    
    /**
     * Enqueue pipelined responses with the writer (maintaining the original request order)
     * 
     * @return Returns the number of queued responses for which writing has yet to complete
     */
    function enqueueResponsesForWrite() {
        $pendingResponses = 0;
        
        foreach ($this->requests as $requestId => $asgiEnv) {
            if ($this->isWriting[$requestId]) {
                $pendingResponses++;
                continue;
            }
            
            if (isset($this->responses[$requestId])) {
                $this->writer->enqueue($this->responses[$requestId]);
                $this->isWriting[$requestId] = TRUE;
                $pendingResponses++;
            } else {
                break;
            }
        }
        
        return $pendingResponses;
    }
    
    function write() {
        return $this->writer->write();
    }
    
    function front() {
        reset($this->requests);
        $requestId = key($this->requests);
        
        return [$requestId, $this->requests[$requestId], $this->responses[$requestId]];
    }
    
    function shift() {
        reset($this->requests);
        $requestId = key($this->requests);
        
        unset(
            $this->requests[$requestId],
            $this->responses[$requestId],
            $this->isWriting[$requestId]
        );
        
        return $requestId;
    }
    
    function read() {
        return $this->reader->read();
    }
    
    function hasUnfinishedRead() {
        return $this->reader->inProgress();
    }
    
    function getRequestIds() {
        return $this->requests ? array_keys($this->requests) : [];
    }
    
}


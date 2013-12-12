<?php

namespace Aerys;

class MessageCycle {

    public $requestId;
    public $client;
    public $host;
    public $protocol;
    public $isHttp11;
    public $isHttp10;
    public $method;
    public $headers = [];
    public $ucHeaders = [];
    public $body;
    public $uri;
    public $uriHost;
    public $uriPort;
    public $uriPath;
    public $uriQuery;
    public $hasAbsoluteUri;
    public $request;
    public $response;
    public $shouldCloseAfterSend;
    public $yieldGroup = [];
    public $yieldGroupResults = [];
    
    public function storeYieldGroup(array $yieldGroup) {
        $this->yieldGroup = $yieldGroup;
    }

    public function hasYieldGroup() {
        return (bool) $this->yieldGroup;
    }

    public function submitYieldGroupResult($id, $result) {
        $this->yieldGroupResults[$id] = $result;

        if (array_diff_key($this->yieldGroup, $this->yieldGroupResults)) {
            $completedResult = NULL;
        } else {
            $completedResult = $this->yieldGroupResults;
            $this->yieldGroupResults = [];
        }

        return $completedResult;
    }

}

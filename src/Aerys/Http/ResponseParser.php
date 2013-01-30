<?php

namespace Aerys\Http;

class ResponseParser extends MessageParser {
    
    const STATUS_LINE_PATTERN = "#^
        HTTP/(?P<protocol>\d+\.\d+)[\x20\x09]+
        (?P<status>[1-5]\d\d)[\x20\x09]*
        (?P<reason>[^\x01-\x08\x10-\x19]*)
    $#ix";
    
    private $protocol;
    private $status;
    private $reason;
    
    function getProtocol() {
        return $this->protocol;
    }
    
    function getStatus() {
        return $this->status;
    }
    
    function getReason() {
        return $this->reason;
    }
    
    /**
     * @throws ParseException On invalid status line
     * @return void
     */
    protected function parseStartLine($rawStartLine) {
        if (preg_match(self::STATUS_LINE_PATTERN, $rawStartLine, $m)) {
            $this->protocol = $m['protocol'];
            $this->status = $m['status'];
            $this->reason = $m['reason'];
        } else {
            var_dump($rawStartLine);die;
            throw new ParseException(
                "Invalid status line",
                self::E_START_LINE_SYNTAX
            );
        }
    }
    
    protected function allowsEntityBody() {
        return !($this->status == 204 || $this->status == 304 || $this->status < 200);
    }
    
    protected function getParsedMessageVals() {
        return array(
            'protocol' => $this->protocol,
            'status' => $this->status,
            'reason' => $this->reason,
            'headers' => $this->getHeaders(),
            'body' => $this->getBody()
        );
    }
    
    protected function resetForNextMessage() {
        parent::resetForNextMessage();
        
        $this->method = NULL;
        $this->uri = NULL;
        $this->protocol = NULL;
    }
    
    function getStatus() {
        return $this->status;
    }
    
    function getReason() {
        return $this->reason;
    }
    
    function getProtocol() {
        return $this->protocol;
    }
    
}

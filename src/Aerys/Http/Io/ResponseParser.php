<?php

namespace Aerys\Http\Io;

class ResponseParser extends MessageParser {
    
    const STATUS_LINE_PATTERN = "#^
        HTTP/(?P<protocol>\d+\.\d+)[\x20\x09]+
        (?P<status>[1-5]\d\d)[\x20\x09]*
        (?P<reason>[^\x01-\x08\x10-\x19]*)
    $#ix";
    
    protected $status;
    protected $reason;
    
    /**
     * @throws ParseException On invalid status line
     * @return void
     */
    protected function parseStartLine($rawStartLine) {
        if (preg_match(self::STATUS_LINE_PATTERN, $rawStartLine, $m)) {
            $this->protocol = $m['protocol'];
            $this->status   = $m['status'];
            $this->reason   = $m['reason'];
        } else {
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
        $headers = [];
        foreach ($this->headers as $key => $arr) {
            $headers[$key] = isset($arr[1]) ? $arr : $arr[0];
        }
        
        return [
            'protocol' => $this->protocol,
            'status'   => $this->status,
            'reason'   => $this->reason,
            'headers'  => $headers,
            'body'     => $this->body,
            'trace'    => $this->traceBuffer
        );
    }
    
    protected function resetForNextMessage() {
        $this->state = self::START_LINE;
        $this->traceBuffer = NULL;
        $this->headers = [];
        $this->body = NULL;
        $this->bodyBytesConsumed = 0;
        $this->remainingBodyBytes = NULL;
        $this->currentChunkSize = NULL;
        $this->protocol = NULL;
        $this->status = NULL;
        $this->reason = NULL;
    }
    
}

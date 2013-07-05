<?php

namespace Aerys\Handlers\Websocket;

class FrameStreamSequence extends FrameStream {
    
    private $sequence;
    private $currentCache;
    private $buffer;
    
    protected function setDataSource($dataSource) {
        // We don't bother to validate here as the FrameStreamFactory already validates in practice
        // $this->validateSeekableIterator($dataSource);
        
        $this->sequence = $dataSource;
    }
    
    /**
     * @codeCoverageIgnore
     */
    private function validateSeekableIterator($dataSource) {
        if (!$dataSource instanceof \SeekableIterator) {
            throw new \InvalidArgumentException(
                'SeekableIterator instance required at '.__CLASS__.'::'.__METHOD__.' Argument 1'
            );
        }
    }
    
    function count() {
        return $this->sequence->count();
    }
    
    function rewind() {
        $this->currentCache = NULL;
        return $this->sequence->rewind();
    }
    
    function valid() {
        return $this->sequence->valid();
    }
    
    function key() {
        return $this->sequence->key();
    }
    
    function current() {
        if (isset($this->currentCache)) {
            return $this->currentCache;
        }
        
        $current = $this->sequence->current();
        if (!($current || $current === '0')) {
            return NULL;
        }
        
        $this->buffer .= $current;
        $bufferSize = strlen($this->buffer);
        
        if (!$this->frameSize || $this->frameSize > $bufferSize) {
            $this->currentCache = $this->buffer;
            $this->buffer = NULL;
        } else {
            $this->currentCache = substr($this->buffer, 0, $this->frameSize);
            $this->buffer = substr($this->buffer, $this->frameSize);
        }
        
        return $this->currentCache;
    }

    function next() {
        $this->currentCache = NULL;
        return $this->sequence->next();
    }
    
    function seek($position) {
        $this->currentCache = NULL;
        return $this->sequence->seek($position);
    }
}

<?php

namespace Aerys\Writing;

class ChunkedIteratorWriter extends Writer {

    const CHUNK_DELIMITER = "\r\n";
    const FINAL_CHUNK = "0\r\n\r\n";

    private $body;
    private $hasBufferedFinalChunk = FALSE;

    function __construct($destination, $rawHeaders, \Iterator $body) {
        parent::__construct($destination, $rawHeaders);
        $this->body = $body;
    }

    function write() {
        if (!$this->hasBufferedFinalChunk) {
            $this->bufferNextChunk();
        }

        return parent::write() && $this->hasBufferedFinalChunk;
    }

    private function bufferNextChunk() {
        $nextChunkData = $this->body->current();
        $this->body->next();

        if ($nextChunkData || $nextChunkData === '0') {
            $chunkSize = strlen($nextChunkData);
            $buffer = dechex($chunkSize) . self::CHUNK_DELIMITER . $nextChunkData . self::CHUNK_DELIMITER;
            $this->bufferData($buffer);
        }

        if (!$this->body->valid()) {
            $this->bufferData(self::FINAL_CHUNK);
            $this->hasBufferedFinalChunk = TRUE;
        }
    }

}

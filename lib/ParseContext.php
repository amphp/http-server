<?php

namespace Aerys;

abstract class ParseContext extends \Amp\Struct {
    public $state;
    public $buffer;
    public $traceBuffer;
    public $protocol;
    public $headers = [];
    public $body = "";
    public $isChunked;
    public $contentLength;
    public $bodyBufferSize;
    public $bodyBytesConsumed;
    public $chunkLenRemaining;
    public $remainingBodyBytes;
    public $maxHeaderSize = 32768;
    public $maxBodySize = 131072;
    public $bodyEmitSize = 32768;
    public $emitCallback;
    public $appData;
}

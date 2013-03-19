<?php

namespace Aerys\Handlers\Websocket\Io;

use Aerys\Handlers\Websocket\Frame;

/**
 * @TODO Don't use php://temp because we can't access the resource's filepath and that prevents
 * multiprocess handling of message payloads by downstream applications
 * 
 * @TODO Support "onFrame" callbacks to standardize extension transformations and frame logging/debugging
 */
class FrameParser {
    
    const START = 0;
    const DETERMINE_LENGTH_126 = 5;
    const DETERMINE_LENGTH_127 = 10;
    const DETERMINE_MASKING_KEY = 15;
    const CONTROL_PAYLOAD = 20;
    const PAYLOAD = 25;
    const PAYLOAD_WRITE = 30;
    
    private $inputStream;
    private $state = self::START;
    
    private $fin;
    private $rsv;
    private $opcode;
    private $isMasked;
    private $maskingKey;
    private $length;
    private $payload;
    
    private $controlPayload;
    private $isControlFrame;
    private $aggregatedFrames = [];
    
    private $frameBytesRecd = 0;
    private $msgBytesRecd = 0;
    private $writeBuffer;
    private $writeBufferSize;
    
    private $granularity = 8192;
    private $maxFrameSize = 0;
    private $msgSwapSize = 2097152;
    private $requireMask = TRUE;
    private $maxMsgSize = 0;
    
    function __construct($inputStream) {
        $this->inputStream = $inputStream;
    }
    
    function setGranularity($bytes) {
        $this->granularity = (int) $bytes;
    }
    
    function setMaxFrameSize($bytes) {
        $this->maxFrameSize = (int) $bytes;
    }
    
    function setMaxMsgSize($bytes) {
        $this->maxMsgSize = (int) $bytes;
    }
    
    function setMsgSwapSize($bytes) {
        $this->msgSwapSize = (int) $bytes;
    }
    
    function requireMask($boolFlag) {
        $this->requireMask = (bool) $boolFlag;
    }
    
    function parse() {
        $data = fread($this->inputStream, $this->granularity);
        $emptyData = !($data || $data === '0');
        
        if ($emptyData && (!is_resource($this->inputStream) || feof($this->inputStream))) {
            throw new ResourceException(
                'Failed reading from input stream'
            );
        } elseif ($emptyData) {
            goto more_data_needed;
        }
        
        $this->buffer .= $data;
        $this->bufferSize = strlen($this->buffer);
        
        switch ($this->state) {
            case self::START:
                goto start;
            case self::DETERMINE_LENGTH_126:
                goto determine_length_126;
            case self::DETERMINE_LENGTH_127:
                goto determine_length_127;
            case self::DETERMINE_MASKING_KEY:
                goto determine_masking_key;
            case self::CONTROL_PAYLOAD:
                goto control_payload;
            case self::PAYLOAD:
                goto payload;
            case self::PAYLOAD_WRITE:
                goto payload_write;
            default:
                throw new \DomainException(
                    'Unexpected frame parsing state'
                );
        }
        
        start: {
            if ($this->bufferSize < 2) {
                goto more_data_needed;
            }
            
            $firstByte = ord($this->buffer[0]);
            $secondByte = ord($this->buffer[1]);
            
            $this->buffer = substr($this->buffer, 2);
            $this->bufferSize -= 2;
            
            $this->fin = (bool) ($firstByte & 0b10000000);
            $this->rsv = ($firstByte & 0b01110000) >> 4;
            $this->opcode = $firstByte & 0b00001111;
            $this->isMasked = (bool) ($secondByte & 0b10000000);
            $this->maskingKey = NULL;
            $this->length = $secondByte & 0b01111111;
            
            $this->isControlFrame = ($this->opcode >= 0x08);
            
            if ($this->length === 0x7E) {
                $this->state = self::DETERMINE_LENGTH_126;
                goto determine_length_126;
            } elseif ($this->length === 0x7F) {
                $this->state = self::DETERMINE_LENGTH_127;
                goto determine_length_127;
            } else {
                goto validate_header;
            }
        }
        
        determine_length_126: {
            if ($this->bufferSize < 2) {
                goto more_data_needed;
            } else {
                $this->length = current(unpack('n', $this->buffer[0] . $this->buffer[1]));
                $this->buffer = substr($this->buffer, 2);
                $this->bufferSize -= 2;
                goto validate_header;
            }
        }
        
        determine_length_127: {
            if ($this->bufferSize < 8) {
                goto more_data_needed;
            } else {
                $this->determineLength127();
                goto validate_header;
            }
        }
        
        validate_header: {
            if ($this->isControlFrame && !$this->fin) {
                throw new ParseException(
                    'Illegal control frame fragmentation'
                );
            } elseif ($this->maxFrameSize && $this->length > $this->maxFrameSize) {
                throw new PolicyException(
                    'Payload exceeds maximum allowable frame size'
                );
            } elseif ($this->maxMsgSize
                && ($this->length + $this->msgBytesRecd) > $this->maxMsgSize
            ) {
                throw new PolicyException(
                    'Payload exceeds maximum allowable message size'
                );
            } elseif ($this->requireMask && $this->length && !$this->isMasked) {
                throw new ParseException(
                    'Payload mask required'
                );
            }
            
            if (!$this->length) {
                goto frame_complete;
            } else {
                $this->state = self::DETERMINE_MASKING_KEY;
                goto determine_masking_key;
            }
        }
        
        determine_masking_key: {
            if (!$this->isMasked && $this->isControlFrame) {
                $this->state = self::CONTROL_PAYLOAD;
                goto control_payload;
            } elseif (!$this->isMasked) {
                $this->state = self::PAYLOAD;
                goto payload;
            } elseif ($this->bufferSize < 4) {
                goto more_data_needed;
            }
            
            $this->maskingKey = substr($this->buffer, 0, 4);
            $this->buffer = substr($this->buffer, 4);
            $this->bufferSize -= 4;
            
            if (!$this->length) {
                goto frame_complete;
            } elseif ($this->isControlFrame) {
                $this->state = self::CONTROL_PAYLOAD;
                goto control_payload;
            } else {
                $this->state = self::PAYLOAD;
                goto payload;
            }
        }
        
        control_payload: {
            $this->controlPayload .= $this->generateFrameDataChunk();
            $isFrameFullyRecd = ($this->frameBytesRecd == $this->length);
            
            if ($isFrameFullyRecd) {
                goto frame_complete;
            } else {
                goto more_data_needed;
            }
        }
        
        payload: {
            $dataChunk = $this->generateFrameDataChunk();
            $isFrameFullyRecd = ($this->frameBytesRecd == $this->length);
            
            $this->writeBufferSize += strlen($dataChunk);
            $this->writeBuffer .= $dataChunk;
            
            if ($this->writePayload() && $isFrameFullyRecd) {
                goto frame_complete;
            } elseif ($isFrameFullyRecd) {
                $this->state = self::PAYLOAD_WRITE;
                goto further_writing_required;
            } else {
                goto more_data_needed;
            }
        }
        
        payload_write: {
            if ($this->writePayload()) {
                goto frame_complete;
            } else {
                goto further_writing_required;
            }
        }
        
        frame_complete: {
            $frameStruct = [
                'fin'        => $this->fin,
                'rsv'        => $this->rsv,
                'opcode'     => $this->opcode,
                'maskingKey' => $this->maskingKey,
                'length'     => $this->length
            ];
            
            if ($this->isControlFrame) {
                $payload = $this->controlPayload;
                $length = $this->length;
                $this->controlPayload = NULL;
                $opcode = $this->opcode;
                $componentFrames = [$frameStruct];
                $isFinalFrame = TRUE;
            } elseif ($this->fin) {
                $opcode = $this->aggregatedFrames ? $this->aggregatedFrames[0]['opcode'] : $this->opcode;
                $componentFrames = $this->aggregatedFrames ?: [$frameStruct];
                $this->aggregatedFrames = [];
                $payload = $this->payload;
                rewind($payload);
                $this->payload = NULL;
                $length = $this->msgBytesRecd;
                $this->msgBytesRecd = 0;
                $isFinalFrame = TRUE;
            } else {
                $this->aggregatedFrames[] = $frameStruct;
                $isFinalFrame = FALSE;
            }
            
            $this->state = self::START;
            $this->fin = NULL;
            $this->rsv = NULL;
            $this->opcode = NULL;
            $this->isMasked = NULL;
            $this->maskingKey = NULL;
            $this->length = NULL;
            $this->frameBytesRecd = 0;
            
            if ($isFinalFrame) {
                return [$opcode, $payload, $length, $componentFrames];
            } elseif ($this->bufferSize) {
                goto start;
            } else {
                goto more_data_needed;
            }
        }
        
        more_data_needed: {
            return NULL;
        }
        
        further_writing_required: {
            return NULL;
        }
    }
    
    private function determineLength127() {
        $lengthLong32Pair = unpack('NN', substr($this->buffer, 0, 8));
        
        $this->buffer = substr($this->buffer, 8);
        $this->bufferSize -= 8;
        
        if (PHP_INT_MAX === 0x7fffffff) {
            if ($lengthLong32Pair[0] !== 0 || $lengthLong32Pair[1] < 0) {
                throw new PolicyException(
                    'Payload exceeds maximum allowable size'
                );
            }
            
            $this->length = $lengthLong32Pair[1];
            
        } else {
        
            $this->length = ($lengthLong32Pair[0] << 32) | $lengthLong32Pair[1];
            
            if ($this->length < 0) {
                throw new ParseException(
                    'Most significant bit of 64-bit length field set'
                );
            }
        }
    }
    
    private function generateFrameDataChunk() {
        $dataLen = (($this->bufferSize + $this->frameBytesRecd) >= $this->length)
            ? $this->length - $this->frameBytesRecd
            : $this->bufferSize;
        
        $data = substr($this->buffer, 0, $dataLen);
        
        if ($this->isMasked) {
            for ($i=0; $i < $dataLen; $i++) {
                $maskPos = ($this->frameBytesRecd + $i) % 4;
                $data[$i] = $data[$i] ^ $this->maskingKey[$maskPos];
            }
        }
        
        $this->frameBytesRecd += $dataLen;
        $this->msgBytesRecd += $dataLen;
        $this->buffer = substr($this->buffer, $dataLen);
        $this->bufferSize -= $dataLen;
        
        return $data;
    }
    
    private function writePayload() {
        if (!$this->payload) {
            $uri = 'php://temp/maxmemory:' . $this->msgSwapSize;
            $this->payload = fopen($uri, 'wb+');
        }
        
        $bytesWritten = fwrite($this->payload, $this->writeBuffer);
        
        $this->writeBuffer = substr($this->writeBuffer, $bytesWritten);
        $this->writeBufferSize -= $bytesWritten;
        
        if (!$this->writeBufferSize) {
            return TRUE;
        } elseif ($bytesWritten) {
            return FALSE;
        } elseif (!is_resource($this->payload)) {
            throw new \RuntimeException(
                'Failed writing temporary frame data'
            );
        }
    }
    
}


<?php

namespace Aerys\Handlers\Websocket;

class FrameParser {
    
    const START = 0;
    const DETERMINE_LENGTH_126 = 5;
    const DETERMINE_LENGTH_127 = 10;
    const DETERMINE_MASKING_KEY = 15;
    const CONTROL_PAYLOAD = 20;
    const PAYLOAD = 25;
    
    private $state = self::START;
    private $buffer;
    private $fin;
    private $rsv;
    private $opcode;
    private $isMasked;
    private $maskingKey;
    private $length;
    private $framePayloadBuffer;
    private $msgPayload;
    private $controlPayload;
    private $isControlFrame;
    private $aggregatedFrames = [];
    private $frameBytesRecd = 0;
    private $msgBytesRecd = 0;
    
    private $validateUtf8Text = TRUE;
    private $maxFrameSize = 262144;
    private $msgSwapSize = 2097152;
    private $maxMsgSize = 10485760;
    private $requireMask = TRUE;
    private $storePayload = TRUE;
    private $onFrame;
    
    private static $availableOptions = [
        'maxFrameSize' => 1,
        'maxMsgSize' => 1,
        'msgSwapSize' => 1,
        'requireMask' => 1,
        'storePayload' => 1,
        'onFrame' => 1,
        'validateUtf8Text' => 1
    ];
    
    function setOptions(array $options) {
        if ($options = array_intersect_key($options, self::$availableOptions)) {
            foreach ($options as $key => $value) {
                $this->{$key} = $value;
            }
        }
        
        return $this;
    }
    
    function parse($data) {
        $this->buffer .= $data;
        
        if (!($this->buffer || $this->buffer === '0')) {
            goto more_data_needed;
        }
        
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
            } elseif ($this->maxMsgSize && ($this->length + $this->msgBytesRecd) > $this->maxMsgSize) {
                throw new PolicyException(
                    'Payload exceeds maximum allowable message size'
                );
            } elseif ($this->requireMask && $this->length && !$this->isMasked) {
                throw new ParseException(
                    'Payload mask required'
                );
            } elseif (!($this->opcode || $this->isControlFrame || $this->aggregatedFrames)) {
                throw new ParseException(
                    'Illegal CONTINUATION opcode; initial message data frame must be TEXT or BINARY'
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
            $this->framePayloadBuffer .= $this->generateFrameDataChunk();
            
            if ($this->frameBytesRecd === $this->length) {
                goto frame_complete;
            } else {
                goto more_data_needed;
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
            
            if ($this->storePayload && !$this->isControlFrame) {
                $this->addToMessagePayloadStream($this->framePayloadBuffer);
            }
            
            if ($this->fin && !$this->isControlFrame && $this->msgPayload) {
                rewind($this->msgPayload);
            }
            
            if ($this->isControlFrame) {
                $msgPayload = $this->controlPayload;
                $length = $this->length;
                $this->controlPayload = NULL;
                $opcode = $this->opcode;
                $componentFrames = [$frameStruct];
                $isFinalFrame = TRUE;
            } elseif ($this->fin) {
                $opcode = $this->aggregatedFrames ? $this->aggregatedFrames[0]['opcode'] : $this->opcode;
                $componentFrames = $this->aggregatedFrames ?: [$frameStruct];
                $this->aggregatedFrames = [];
                $msgPayload = $this->msgPayload;
                $length = $this->msgBytesRecd;
                $this->msgBytesRecd = 0;
                $this->msgPayload = NULL;
                $isFinalFrame = TRUE;
            } else {
                $this->aggregatedFrames[] = $frameStruct;
                $isFinalFrame = FALSE;
            }
            
            if ($onFrame = $this->onFrame) {
                $frame = [$this->fin, $this->rsv, $this->opcode, $this->framePayloadBuffer, $this->maskingKey];
                $onFrame($frame);
            }
            
            $this->state = self::START;
            $this->fin = NULL;
            $this->rsv = NULL;
            $this->opcode = NULL;
            $this->isMasked = NULL;
            $this->maskingKey = NULL;
            $this->length = NULL;
            $this->frameBytesRecd = 0;
            $this->framePayloadBuffer = NULL;
            
            if ($isFinalFrame) {
                return [$opcode, $msgPayload, $length, $componentFrames];
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
        $lengthLong32Pair = array_values(unpack('N2', substr($this->buffer, 0, 8)));
        $this->buffer = substr($this->buffer, 8);
        $this->bufferSize -= 8;
        
        if (PHP_INT_MAX === 0x7fffffff) {
            $this->validateFrameLengthFor32BitEnvironment($lengthLong32Pair);
            $this->length = $lengthLong32Pair[1];
        } else {
            $length = ($lengthLong32Pair[0] << 32) | $lengthLong32Pair[1];
            $this->validateFrameLengthFor64BitEnvironment($length);
            $this->length = $length;
        }
    }
    
    private function validateFrameLengthFor32BitEnvironment($lengthLong32Pair) {
        if ($lengthLong32Pair[0] !== 0 || $lengthLong32Pair[1] < 0) {
            throw new PolicyException(
                'Payload exceeds maximum allowable size'
            );
        }
    }
    
    private function validateFrameLengthFor64BitEnvironment($length) {
        if ($length < 0) {
            throw new ParseException(
                'Most significant bit of 64-bit length field set'
            );
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
        
        if ($this->opcode === Frame::OP_TEXT
            && $this->validateUtf8Text
            && !preg_match('//u', $data)
        ) {
            throw new ParseException(
                'Invalid TEXT data; UTF-8 required'
            );
        }
        
        $this->frameBytesRecd += $dataLen;
        $this->msgBytesRecd += $dataLen;
        $this->buffer = substr($this->buffer, $dataLen);
        $this->bufferSize -= $dataLen;
        
        return $data;
    }
    
    private function addToMessagePayloadStream($data) {
        if (!$this->msgPayload) {
            $uri = 'php://temp/maxmemory:' . $this->msgSwapSize;
            $this->msgPayload = fopen($uri, 'wb+');
        }
        
        if (FALSE === @fwrite($this->msgPayload, $data)) {
            throw new \RuntimeException(
                'Failed writing frame data to temporary stream'
            );
        }
    }
    
}


<?php

namespace Aerys\Websocket;

class Rfc6455Parser {
    /* Frame control bits */
    const FIN      = 0b1;
    const RSV_NONE = 0b000;
    const OP_CONT  = 0x00;
    const OP_TEXT  = 0x01;
    const OP_BIN   = 0x02;
    const OP_CLOSE = 0x08;
    const OP_PING  = 0x09;
    const OP_PONG  = 0x0A;

    const CONTROL = 1;
    const DATA = 2;
    const ERROR = 3;

    // parse state constants (these
    const START = 0;
    const LENGTH_126 = 1;
    const LENGTH_127 = 2;
    const MASKING_KEY = 3;
    const PAYLOAD = 4;

    private $emitCallback;
    private $callbackData;
    private $emitThreshold;
    private $maxFrameSize;
    private $maxMsgSize;
    private $textOnly;
    private $validateUtf8;

    private $state = self::START;
    private $buffer;
    private $bufArr;
    private $frameLength;
    private $frameBytesRecd = 0;
    private $dataMsgBytesRecd = 0;
    private $dataPayload;
    private $dataArr;
    private $controlPayload;
    private $isControlFrame;

    private $fin;
    private $rsv;
    private $opcode;
    private $isMasked;
    private $maskingKey;

    public function __construct(callable $emitCallback, $options = []) {
        $this->emitCallback = $emitCallback;
        $this->callbackData = $options["cb_data"] ?? null;
        $this->emitThreshold = $options["threshold"] ?? 32768;
        $this->maxFrameSize = $options["max_frame_size"] ?? PHP_INT_MAX;
        $this->maxMsgSize = $options["max_msg_size"] ?? PHP_INT_MAX;
        $this->textOnly = $options["text_only"] ?? false;
        $this->validateUtf8 = $options["validate_utf8"] ?? false;
    }


    public function sink(string $buffer) {
        $frames = 0;

        if ($this->buffer != "") {
            $buffer = $this->buffer . $buffer;
        }
        $bufferSize = strlen($buffer);

        switch ($this->state) {
            case self::START:
                goto start;
            case self::LENGTH_126:
                goto determine_length_126;
            case self::LENGTH_127:
                goto determine_length_127;
            case self::MASKING_KEY:
                goto determine_masking_key;
            case self::PAYLOAD:
                $this->bufArr[] = $buffer;
                goto payload;
            default:
                throw new \UnexpectedValueException(
                    'Unexpected frame parsing state'
                );
        }

        start: {
            if ($bufferSize < 2) {
                goto more_data_needed;
            }

            $firstByte = ord($buffer[0]);
            $secondByte = ord($buffer[1]);

            $buffer = substr($buffer, 2);
            $bufferSize -= 2;

            $this->fin = (bool)($firstByte & 0b10000000);
            $this->rsv = ($firstByte & 0b01110000) >> 4;
            $this->opcode = $firstByte & 0b00001111;
            $this->isMasked = (bool)($secondByte & 0b10000000);
            $this->maskingKey = null;
            $this->frameLength = $secondByte & 0b01111111;

            $this->isControlFrame = ($this->opcode >= 0x08);

            if ($this->frameLength === 0x7E) {
                $this->state = self::LENGTH_126;
                goto determine_length_126;
            } elseif ($this->frameLength === 0x7F) {
                $this->state = self::LENGTH_127;
                goto determine_length_127;
            } else {
                goto validate_header;
            }
        }

        determine_length_126: {
            if ($bufferSize < 2) {
                goto more_data_needed;
            } else {
                $this->frameLength = current(unpack('n', $buffer[0] . $buffer[1]));
                $buffer = substr($buffer, 2);
                $bufferSize -= 2;
                goto validate_header;
            }
        }

        determine_length_127: {
            if ($bufferSize < 8) {
                goto more_data_needed;
            }

            $lengthLong32Pair = array_values(unpack('N2', substr($buffer, 0, 8)));
            $buffer = substr($buffer, 8);
            $bufferSize -= 8;

            if (PHP_INT_MAX === 0x7fffffff) {
                goto validate_length_127_32bit;
            } else {
                goto validate_length_127_64bit;
            }
        }

        validate_length_127_32bit: {
            if ($lengthLong32Pair[0] !== 0 || $lengthLong32Pair[1] < 0) {
                $code = CODES["MESSAGE_TOO_LARGE"];
                $errorMsg = 'Payload exceeds maximum allowable size';
                goto error;
            }
            $this->frameLength = $lengthLong32Pair[1];

            goto validate_header;
        }

        validate_length_127_64bit: {
            $length = ($lengthLong32Pair[0] << 32) | $lengthLong32Pair[1];
            if ($length < 0) {
                $code = CODES["PROTOCOL_ERROR"];
                $errorMsg = 'Most significant bit of 64-bit length field set';
                goto error;
            }
            $this->frameLength = $length;

            goto validate_header;
        }

        validate_header: {
            if ($this->isControlFrame && !$this->fin) {
                $code = CODES["PROTOCOL_ERROR"];
                $errorMsg = 'Illegal control frame fragmentation';
                goto error;
            } elseif ($this->isControlFrame && $this->frameLength > 125) {
                $code = CODES["PROTOCOL_ERROR"];
                $errorMsg = 'Control frame payload must be of maximum 125 bytes or less';
                goto error;
            } elseif ($this->maxFrameSize && $this->frameLength > $this->maxFrameSize) {
                $code = CODES["MESSAGE_TOO_LARGE"];
                $errorMsg = 'Payload exceeds maximum allowable frame size';
                goto error;
            } elseif ($this->maxMsgSize && ($this->frameLength + $this->dataMsgBytesRecd) > $this->maxMsgSize) {
                $code = CODES["MESSAGE_TOO_LARGE"];
                $errorMsg = 'Payload exceeds maximum allowable message size';
                goto error;
            } elseif ($this->textOnly && $this->opcode === 0x02) {
                $code = CODES["UNACCEPTABLE_TYPE"];
                $errorMsg = 'BINARY opcodes (0x02) not accepted';
                goto error;
            } elseif ($this->frameLength > 0 && !$this->isMasked) {
                $code = CODES["PROTOCOL_ERROR"];
                $errorMsg = 'Payload mask required';
                goto error;
            } elseif (!($this->opcode || $this->isControlFrame)) {
                $code = CODES["PROTOCOL_ERROR"];
                $errorMsg = 'Illegal CONTINUATION opcode; initial message payload frame must be TEXT or BINARY';
                goto error;
            }

            if (!$this->frameLength) {
                goto frame_complete;
            } else {
                $this->state = self::MASKING_KEY;
                goto determine_masking_key;
            }
        }

        determine_masking_key: {
            if (!$this->isMasked) {
                $this->state = self::PAYLOAD;
                $this->buffer = "";
                goto payload;
            } elseif ($bufferSize < 4) {
                goto more_data_needed;
            }

            $this->maskingKey = substr($buffer, 0, 4);
            $buffer = substr($buffer, 4);
            $bufferSize -= 4;

            if (!$this->frameLength) {
                goto frame_complete;
            }

            $this->state = self::PAYLOAD;
            $this->buffer = "";
            goto payload;
        }

        payload: {
            if (($bufferSize + $this->frameBytesRecd) >= $this->frameLength) {
                $dataLen = $this->frameLength - $this->frameBytesRecd;
            } else {
                $dataLen = $bufferSize;
            }

            if ($this->isControlFrame) {
                $payloadReference =& $this->controlPayload;
            } else {
                $payloadReference =& $this->dataPayload;
                $this->dataMsgBytesRecd += $dataLen;
            }

            $payloadReference .= substr($buffer, 0, $dataLen);
            $this->frameBytesRecd += $dataLen;

            $buffer = substr($buffer, $dataLen);
            $bufferSize -= $dataLen;

            if ($this->frameBytesRecd == $this->frameLength) {
                goto frame_complete;
            } else {
                // if we want to validate UTF8, we must *not* send incremental mid-frame updates because the message might be broken in the middle of an utf-8 sequence
                // also, control frames always are <= 125 bytes, so we never will need this @link https://tools.ietf.org/html/rfc6455#section-5.5
                if (!$this->isControlFrame && !($this->opcode === self::OP_TEXT && $this->validateUtf8) && $this->dataMsgBytesRecd >= $this->emitThreshold) {
                    if ($this->isMasked) {
                        $payloadReference ^= str_repeat($this->maskingKey, ($this->frameBytesRecd + 3) >> 2);
                        // Shift the mask so that the next data where the mask is used on has correct offset.
                        $this->maskingKey = substr($this->maskingKey . $this->maskingKey, $this->frameBytesRecd % 4, 4);
                    }

                    call_user_func($this->emitCallback, [self::DATA, $payloadReference, false], $this->callbackData);

                    $this->frameLength -= $this->frameBytesRecd;
                    $this->frameBytesRecd = 0;
                    $payloadReference = '';
                }

                return $frames;
            }
        }

        frame_complete: {
            $payloadReference = isset($payloadReference) ? $payloadReference : '';

            if ($this->isMasked) {
                // This is memory hungry but it's ~70x faster than iterating byte-by-byte
                // over the masked string. Deal with it; manual iteration is untenable.
                $payloadReference ^= str_repeat($this->maskingKey, ($this->frameLength + 3) >> 2);
            }

            if ($this->opcode === self::OP_TEXT && $this->validateUtf8 && !preg_match('//u', $payloadReference)) {
                $code = CODES["INCONSISTENT_FRAME_DATA_TYPE"];
                $errorMsg = 'Invalid TEXT data; UTF-8 required';
                goto error;
            }

            $frames++;

            if ($this->fin || $this->dataMsgBytesRecd >= $this->emitThreshold) {
                if ($this->isControlFrame) {
                    $emit = [self::CONTROL, $payloadReference, $this->opcode];
                } else {
                    if ($this->dataArr) {
                        $this->dataArr[] = $payloadReference;
                        $payloadReference = implode($this->dataArr);
                        $this->dataArr = [];
                    }

                    $emit = [self::DATA, $payloadReference, $this->fin];
                    $this->dataMsgBytesRecd = 0;
                }

                call_user_func($this->emitCallback, $emit, $this->callbackData);
            } else {
                $this->dataArr[] = $payloadReference;
            }
            $payloadReference = '';

            $this->state = self::START;
            $this->fin = null;
            $this->rsv = null;
            $this->opcode = null;
            $this->isMasked = null;
            $this->maskingKey = null;
            $this->frameLength = null;
            $this->frameBytesRecd = 0;
            $this->isControlFrame = null;

            goto start;
        }

        error: {
            call_user_func($this->emitCallback, [self::ERROR, $errorMsg, $code], $this->callbackData);
            return $frames;
        }

        more_data_needed: {
            $this->buffer = $buffer;
            return $frames;
        }
    }
}
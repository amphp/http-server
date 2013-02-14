<?php

namespace Aerys\Apm;

/*
APM MESSAGE HEADER SPEC

8 byte header for each message/frame

V       - 1 byte unsigned char - APM version
T       - 1 byte unsigned char - APM message type
RID     - 4 byte unsigned int  - APM request ID
LEN     - 4 byte unsigned int  - Content length of the message data
x       - 2 reserved bytes     - Null padded

+-+-+----+-+===========================+
|V|T|LEN |x| LEN bytes of message data |
+-+-+----+-+===========================+


PHP Pack:
    $header = pack('CCNN@12', $version, $type, $length);

PHP Unpack:
    $unpackedArr = unpack('Cversion/Ctype/NrequestId/Nlength', $header);

*/

class Message {
    
    const HEADER_SIZE = 12;
    const HEADER_PACK_PATTERN = 'CCNN@12';
    const HEADER_UNPACK_PATTERN = 'Cversion/Ctype/NrequestId/Nlength';
    
    const REQUEST = 10;
    const RESPONSE = 50;
    const ERROR = 70;
    
    private $version;
    private $type;
    private $requestId;
    private $length;
    private $body;
    
    function __construct($version, $type, $requestId, $length, $body) {
        $this->version = $version;
        $this->type = $type;
        $this->requestId = $requestId;
        $this->length = $length;
        $this->body = $body;
    }
    
    static function generateRaw($version, $type, $requestId, $length, $body) {
        return pack(
            self::HEADER_PACK_PATTERN,
            $version,
            $type,
            $requestId,
            $length
        ) . $body;
    }
    
    function __toString() {
        return pack(
            self::HEADER_PACK_PATTERN,
            $this->version,
            $this->type,
            $this->requestId,
            $this->length
        ) . $this->body;
    }
    
    function toArray() {
        return [
            'version' => $this->version,
            'type' => $this->type,
            'requestId' => $this->requestId,
            'length' => $this->length,
            'body' => $this->body
        ];
    }
    
    function getVersion() {
        return $this->version;
    }
    
    function getType() {
        return $this->type;
    }
    
    function getRequestId() {
        return $this->requestId;
    }
    
    function getLength() {
        return $this->length;
    }
    
    function getBody() {
        return $this->body;
    }
    
}


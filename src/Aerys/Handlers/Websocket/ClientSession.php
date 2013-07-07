<?php

namespace Aerys\Handlers\Websocket;

class ClientSession {
    
    const CLOSE_NOT_INITIALIZED = 0b00;
    const CLOSE_FRAME_RECEIVED = 0b01;
    const CLOSE_FRAME_SENT = 0b10;
    const CLOSE_HANDSHAKE_COMPLETE = 0b11;
    
    public $id;
    public $socket;
    public $socketReadSubscription;
    public $socketWriteSubscription;
    public $closeTimeoutSubscription;
    
    public $asgiEnv;
    public $clientProxy;
    public $endpoint;
    public $endpointOptions;
    public $endpointUri;
    public $frameParser;
    public $frameWriter;
    
    public $outboundFrameStream;
    public $outboundFrameStreamPosition;
    public $outboundFrameStreamQueue = [];
    public $pendingPingPayloads = [];
    
    public $closeState = self::CLOSE_NOT_INITIALIZED;
    public $pendingCloseCode;
    public $pendingCloseReason;
    
    public $controlBytesRead = 0;
    public $controlFramesRead = 0;
    public $controlBytesWritten = 0;
    public $controlFramesWritten = 0;
    public $dataMessagesRead = 0;
    public $dataFramesRead = 0;
    public $dataBytesRead = 0;
    public $dataMessagesWritten = 0;
    public $dataFramesWritten = 0;
    public $dataBytesWritten = 0;
    public $dataLastReadAt;
    public $connectedAt;
    
}

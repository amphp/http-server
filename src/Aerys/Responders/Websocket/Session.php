<?php

namespace Aerys\Responders\Websocket;

class Session {

    const CLOSE_NOT_INITIALIZED = 0b00;
    const CLOSE_FRAME_RECEIVED = 0b01;
    const CLOSE_FRAME_SENT = 0b10;
    const CLOSE_HANDSHAKE_COMPLETE = 0b11;

    public $id;
    public $socket;
    public $asgiEnv;

    public $endpoint;
    public $endpointOptions;
    public $endpointUri;

    public $frameParser;
    public $frameWriter;

    public $frameStream;
    public $frameStreamPosition;
    public $frameStreamQueue = [];
    public $pendingPingPayloads = [];

    public $closeState = self::CLOSE_NOT_INITIALIZED;
    public $pendingCloseCode;
    public $pendingCloseReason;

    public $controlBytesRead = 0;
    public $controlFramesRead = 0;
    public $controlBytesSent = 0;
    public $controlFramesSent = 0;
    public $dataMessagesRead = 0;
    public $dataFramesRead = 0;
    public $dataBytesRead = 0;
    public $dataMessagesSent = 0;
    public $dataFramesSent = 0;
    public $dataBytesSent = 0;
    public $dataLastReadAt;
    public $connectedAt;

    public $heartbeatPeriod;

    public $readWatcher;
    public $writeWatcher;
    public $closeTimeoutWatcher;
}

<?php

namespace Aerys\Websocket;

class Session {
    const HANDSHAKE_NONE = 0;
    const HANDSHAKE_INIT = 1;
    const HANDSHAKE_DONE = 2;
    const CLOSE_NONE = 0b000;
    const CLOSE_INIT = 0b001;
    const CLOSE_RCVD = 0b010;
    const CLOSE_SENT = 0b100;
    const CLOSE_DONE = 0b111;

    public $handshakeState = self::HANDSHAKE_NONE;
    public $handshakeHttpStatus;
    public $handshakeHttpReason;
    public $handshakeHttpHeader;
    public $request;

    public $clientId;
    public $socket;
    public $parser;
    public $parseState;
    public $readWatcher;
    public $writeWatcher;
    public $isWriteWatcherEnabled;
    public $pendingPings = [];
    public $serverCloseCallback;
    public $messageBuffer = '';

    public $closeState = self::CLOSE_NONE;
    public $closeCode;
    public $closeReason;
    public $closePayload;
    public $closePromisor;

    public $writeBuffer = '';
    public $writeBufferSize = 0;
    public $writeDataQueue = [];
    public $writeControlQueue = [];
    public $writeOpcode;
    public $writeIsFin;

    public $bytesRead;
    public $bytesSent;
    public $framesRead;
    public $framesSent;
    public $messagesRead;
    public $messagesSent;
    public $connectedAt;
    public $lastReadAt;
    public $lastSendAt;
}

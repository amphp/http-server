<?php

namespace Aerys\Websocket;

class Session {
    const CLOSE_NONE = 0b000;
    const CLOSE_INIT = 0b001;
    const CLOSE_RECD = 0b010;
    const CLOSE_SENT = 0b100;
    const CLOSE_DONE = 0b111;

    public $clientId;
    public $socket;
    public $stats;
    public $parser;
    public $parseState;
    public $writeState;
    public $readWatcher;
    public $writeWatcher;
    public $pendingPings = [];
    public $serverCloseCallback;
    public $messageBuffer = '';

    public $closeState = self::CLOSE_NONE;
    public $closeCode;
    public $closeReason;
    public $closePayload;
    public $closePromisor;

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

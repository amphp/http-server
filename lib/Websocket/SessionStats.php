<?php

namespace Aerys\Websocket;

class SessionStats {
    public $bytesRead = 0;
    public $bytesSent = 0;
    public $framesRead = 0;
    public $framesSent = 0;
    public $messagesRead = 0;
    public $messagesSent = 0;
    public $connectedAt;
    public $lastReadAt;
    public $lastSendAt;
}

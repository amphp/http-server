<?php

namespace Aerys\Websocket;

class Session {
    public $id;
    public $socket;
    public $stats;
    public $parser;
    public $parseState;
    public $writeState;
    public $closeState;
    public $readWatcher;
    public $writeWatcher;
    public $pendingPings = [];
    public $closer;
    public $messageBuffer = '';
}

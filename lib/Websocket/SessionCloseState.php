<?php

namespace Aerys\Websocket;

class SessionCloseState {
    const INIT = 0b001;
    const RECD = 0b010;
    const SENT = 0b100;
    const DONE = 0b111;
    public $state;
    public $code;
    public $reason;
    public $payload;
}

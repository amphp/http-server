<?php

namespace Aerys\Websocket;

class SessionWriteState {
    public $buffer = '';
    public $bufferSize = 0;
    public $dataQueue = [];
    public $controlQueue = [];
    public $opcode;
    public $fin;
}

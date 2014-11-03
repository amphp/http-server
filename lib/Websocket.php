<?php

namespace Aerys;

interface Websocket {
    const STATUS = 'status';
    const REASON = 'reason';
    const HEADER = 'header';
    const SEND  = 'send';
    const BROADCAST = 'broadcast';
    const INSPECT = 'inspect';
    const CLOSE = 'close';
    const ALL = 'all';
    const ANY = 'any';
    const SOME = 'some';
    const WAIT = 'wait';
    const IMMEDIATELY = 'immediately';
    const ONCE = 'once';
    const REPEAT = 'repeat';
    const ON_READABLE = 'onreadable';
    const ON_WRITABLE = 'onwritable';
    const ENABLE = 'enable';
    const DISABLE = 'disable';
    const CANCEL = 'cancel';
    const NOWAIT = 'nowait';
    const NOWAIT_PREFIX = '@';

    public function onOpen($clientId, array $httpRequestEnv);
    public function onData($clientId, $data);
    public function onClose($clientId, $code, $reason);
}

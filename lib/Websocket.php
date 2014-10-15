<?php

namespace Aerys;

interface Websocket {
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
    const WATCH_STREAM = 'watch-stream';
    const ENABLE = 'enable';
    const DISABLE = 'disable';
    const CANCEL = 'cancel';

    public function onStart();
    public function onOpen($clientId, array $httpEnvironment);
    public function onData($clientId, $dataRcvd, array $context);
    public function onClose($clientId, $closeCode, $closeReason);
    public function onStop();
}

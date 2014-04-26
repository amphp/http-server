<?php

namespace Aerys;

class ResponseWriterSubject {
    public $socket;
    public $writeWatcher;
    public $mustClose;
    public $dateHeader;
    public $serverHeader;
    public $keepAliveHeader;
    public $defaultContentType;
    public $defaultTextCharset;
    public $autoReasonPhrase;
    public $debug;
}

<?php

namespace Aerys;

use Amp\Struct;

class Rfc7230ResponseContext {
    use Struct;
    public $status;
    public $reason;
    public $headers;
    public $isServerStopping;
    public $currentHttpDate;
    public $requestProtocol;
    public $requestsRemaining;
    public $keepAliveTimeout;
    public $autoReasonPhrase;
    public $sendServerToken;
    public $defaultContentType;
    public $defaultTextCharset;
}

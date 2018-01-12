<?php

namespace Aerys\Internal;

use Amp\Struct;

final class Options {
    use Struct;

    public $debug = false;
    public $user = null;
    public $maxConnections = 10000;
    public $connectionsPerIP = 30; // IPv4: /32, IPv6: /56 (per RFC 6177)
    public $maxRequestsPerConnection = 1000; // set to PHP_INT_MAX to disable
    public $connectionTimeout = 15; // seconds

    public $sendServerToken = false;
    public $socketBacklogSize = 128;
    public $normalizeMethodCase = true;
    public $maxConcurrentStreams = 20;
    public $maxFramesPerSecond = 60;
    public $allowedMethods = ["GET", "POST", "PUT", "PATCH", "HEAD", "OPTIONS", "DELETE"];

    public $maxBodySize = 131072;
    public $maxHeaderSize = 32768;
    public $ioGranularity = 32768; // recommended: at least 16 KB
    public $softStreamCap = 131072; // should be multiple of outputBufferSize

    public $outputBufferSize = 8192;
    public $shutdownTimeout = 3000; // milliseconds
}

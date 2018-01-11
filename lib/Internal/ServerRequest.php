<?php

namespace Aerys\Internal;

use Amp\Struct;

class ServerRequest {
    use Struct;

    /** @var \Aerys\Internal\Client */
    public $client;
    /** @var integer */
    public $streamId = 0;
    /** @var string|array literal trace for HTTP/1, for HTTP/2 an array of [name, value] arrays in the original order */
    public $trace;
    /** @var string */
    public $protocol;
    /** @var string */
    public $method;
    /** @var array */
    public $headers = [];
    /** @var \Aerys\Body */
    public $body;
    /** @var int */
    public $maxBodySize = 0;
    /** @var \Amp\Uri\Uri */
    public $uri;
    /** string */
    public $target;
    /** @var array */
    public $cookies = [];
    /** @var int */
    public $time = 0;
    /** @var string */
    public $httpDate;
    /** @var array */
    public $locals = [];
}

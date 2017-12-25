<?php

namespace Aerys\Internal;

use Amp\Struct;

class Request {
    use Struct;

    /** @var \Aerys\Client */
    public $client;
    /** @var \Generator */
    public $responseWriter;
    /** @var array */
    public $badFilterKeys = [];
    /** @var boolean */
    public $filterErrorFlag;
    /** @var integer */
    public $streamId = 0;
    /** @var string|array literal trace for HTTP/1, for HTTP/2 an array of [name, value] arrays in the original order */
    public $trace;
    /** @var string */
    public $protocol;
    /** @var string */
    public $method;
    /** @var array */
    public $headers;
    /** @var \Aerys\Body */
    public $body;
    /** @var int */
    public $maxBodySize;
    /** @var \Amp\Uri\Uri */
    public $uri;
    /** string */
    public $target;
    /** @var array */
    public $cookies = [];
    /** @var int */
    public $time;
    /** @var string */
    public $httpDate;
    /** @var array */
    public $locals = [];
    /** @var callable[] */
    public $onClose = [];
}

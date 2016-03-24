<?php

namespace Aerys;

use Amp\Struct;

class InternalRequest {
    use Struct;

    /** @var Client */
    public $client;
    /** @var \Generator */
    public $responseWriter;
    /** @var array */
    public $badFilterKeys = [];
    /** @var boolean */
    public $filterErrorFlag;
    /** @var integer */
    public $streamId;
    /** @var string|array literal trace for HTTP/1, for HTTP/2 an array of [name, value] arrays in the original order */
    public $trace;
    /** @var string */
    public $protocol;
    /** @var string */
    public $method;
    /** @var array */
    public $headers;
    /** @var Body */
    public $body;
    /** @var int */
    public $maxBodySize;
    /** @var string */
    public $uri;
    /** @var string */
    public $uriRaw;
    /** @var string */
    public $uriHost;
    /** @var integer */
    public $uriPort;
    /** @var string */
    public $uriPath;
    /** @var string */
    public $uriQuery;
    /** @var array */
    public $cookies;
    /** @var int */
    public $time;
    /** @var string */
    public $httpDate;
    /** @var array */
    public $locals;
}

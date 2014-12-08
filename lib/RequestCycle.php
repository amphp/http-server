<?php

namespace Aerys;

/**
 * RequestCycle objects aggregate all information relevant to an entire request-response
 * message cycle in one place. Correct HTTP/1.0 and HTTP/1.1 protocol adherence
 * requires information both from the request and the response to be available
 * for the life of the message, so we store it here. This object also caches
 * client and (expensive-to-parse) URI data associated with various message cycle
 * operations.
 */
class RequestCycle extends \Amp\Struct {
    public $requestId;
    public $client;
    public $host;
    public $protocol;
    public $method;
    public $headers = [];
    public $body;
    public $uri;
    public $uriHost;
    public $uriPort;
    public $uriPath;
    public $uriQuery;
    public $hasAbsoluteUri;
    public $request;
    public $responder;
    public $expectsContinue;
}

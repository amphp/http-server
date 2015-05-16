<?php

namespace Aerys;

use Amp\Promise;

/**
 * An API allowing responders to buffer or stream request entity bodies
 *
 * Applications are invoked as soon as headers are received and before
 * entity body data is parsed. The $request->body instance allows
 * applications to await receipt of the full body (buffer) or stream
 * it in chunks as it arrives.
 *
 * Buffering Example:
 *
 *     $responder = function(Request $request, Response $response) {
 *          $body = yield $request->body->buffer();
 *          $response->send("Echoing back the request body: {$body}");
 *     };
 *
 * Streaming Example:
 *
 *     $responder = function(Request $request, Response $response) {
 *          $body = "";
 *          foreach ($request->body->stream() as $chunk) {
 *              $body .= yield $chunk;
 *          }
 *          $response->send("Echoing back the request body: {$body}");
 *     };
 */
class Body {
    private $promiseStream;

    /**
     * @param \Aerys\PromiseStream $promiseStream
     */
    public function __construct(PromiseStream $promiseStream) {
        $this->promiseStream = $promiseStream;
    }

    /**
     * Return a generator yielding promises to resolve with entity body chunks
     *
     * @return \Generator
     */
    public function stream(): \Generator {
        return $this->promiseStream->stream();
    }

    /**
     * Return a promise that will resolve with the fully buffered entity body
     *
     * @return \Amp\Promise
     */
    public function buffer(): Promise {
        return $this->promiseStream->buffer();
    }
}

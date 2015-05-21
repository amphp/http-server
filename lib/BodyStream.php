<?php

namespace Aerys;

use Amp\{ Streamable, PromiseStream };

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
 *          $bufferedBody = yield $request->body;
 *          $response->send("Echoing back the request body: {$bufferedBody}");
 *     };
 *
 * Streaming Example:
 *
 *     $responder = function(Request $request, Response $response) {
 *          $body = "";
 *          foreach ($request->body->stream() as $bodyPart) {
 *              $body .= yield $bodyPart;
 *          }
 *          $response->send("Echoing back the request body: {$body}");
 *     };
 */
final class BodyStream extends PromiseStream implements Body {
    public function buffer(): \Generator {
        $buffer = "";
        foreach ($this->stream() as $part) {
            $buffer .= yield $part;
        }
        return $buffer;
    }
}

<?php

namespace Aerys;

use Amp\PromiseStream;

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
 *          $bufferedBody = yield $request->getBody();
 *          $response->send("Echoing back the request body: {$bufferedBody}");
 *     };
 *
 * Streaming Example:
 *
 *     $responder = function(Request $request, Response $response) {
 *          $body = "";
 *          foreach ($request->getBody()->stream() as $bodyPart) {
 *              $body .= yield $bodyPart;
 *          }
 *          $response->send("Echoing back the request body: {$body}");
 *     };
 */
class Body extends PromiseStream {}

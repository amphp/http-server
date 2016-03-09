<?php

namespace Aerys;

use Amp\PromiseStream;
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
 *          $bufferedBody = yield $request->getBody();
 *          $response->send("Echoing back the request body: {$bufferedBody}");
 *     };
 *
 * Streaming Example:
 *
 *     $responder = function(Request $request, Response $response) {
 *          $payload = "";
 *          $body = $request->getBody()
 *          while (yield $body->valid()) {
 *              $payload .= $body->consume();
 *          }
 *          $response->send("Echoing back the request body: {$payload}");
 *     };
 */
class Body extends PromiseStream implements Promise {
    private $whens = [];
    private $watchers = [];
    private $string;
    private $error;

    public function __construct(Promise $promise) {
        $promise->watch(function($data) {
            foreach ($this->watchers as list($func, $cbData)) {
                $func($data, $cbData);
            }
        });
        parent::__construct($promise); // DO NOT MOVE - preserve order in which things happen
        $when = function ($e, $bool) use (&$continue) {
            $continue = $bool;
        };
        $promise->when(function() use (&$continue, $when) {
            $this->valid()->when($when);
            while ($continue) {
                $string[] = $this->consume();
                $this->valid()->when($when);
            }
            $this->valid()->when(function ($ex) use (&$e) {
                $e = $ex;
            });

            if (isset($string)) {
                if (isset($string[1])) {
                    $string = implode($string);
                } else {
                    $string = $string[0];
                }

                // way to restart, so that even after the success, the valid() / consume() API will still work
                if (!$e) {
                    $result = $this->consume(); // consume the final result
                }
                $deferred = new \Amp\Deferred;
                parent::__construct($deferred->promise());
                $deferred->update($string);
                if ($e) {
                    $deferred->fail($e);
                } else {
                    $deferred->succeed($result);
                }
            } else {
                $string = "";
            }
            $this->string = $string;
            $this->error = $e;

            foreach ($this->whens as list($when, $data)) {
                $when($e, $string, $data);
            }
            $this->whens = $this->watchers = [];

        });
    }

    public function when(callable $func, $data = null) {
        if (isset($this->string)) {
            $func($this->error, $this->string, $data);
        } else {
            $this->whens[] = [$func, $data];
        }
        return $this;
    }

    public function watch(callable $func, $data = null) {
        if (!isset($this->string)) {
            $this->watchers[] = [$func, $data];
        }
        return $this;
    }
}

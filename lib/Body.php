<?php declare(strict_types = 1);

namespace Aerys;

use Amp\Observable;
use Amp\Observer;
use Amp\Postponed;

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
 *          while (yield $body->next()) {
 *              $payload .= $body->getCurrent();
 *          }
 *          $response->send("Echoing back the request body: {$payload}");
 *     };
 */
class Body extends Observer implements Observable {
    private $whens = [];
    private $watchers = [];
    private $string;
    private $error;

    public function __construct(Observable $observable) {
        $observable->subscribe(function($data) {
            foreach ($this->watchers as $func) {
                $func($data);
            }
        });
        parent::__construct($observable); // DO NOT MOVE - preserve order in which things happen
        $when = static function ($e, $bool) use (&$continue) {
            $continue = $bool;
        };
        $observable->when(function($e, $result) use (&$continue, $when) {
            $this->next()->when($when);
            while ($continue) {
                $string[] = $this->getCurrent();
                $this->next()->when($when);
            }

            $this->next()->when(function ($ex) use (&$e) {
                $e = $ex;
            });

            if (isset($string)) {
                if (isset($string[1])) {
                    $string = implode($string);
                } else {
                    $string = $string[0];
                }

                // way to restart, so that even after the success, the next() / getCurrent() API will still work
                $postponed = new Postponed;
                parent::__construct($postponed->getObservable());
                $postponed->emit($string);
                if ($e) {
                    $postponed->fail($e);
                } else {
                    $postponed->resolve($result);
                }
            } else {
                $string = "";
            }
            $this->string = $string;
            $this->error = $e;

            foreach ($this->whens as $when) {
                $when($e, $string);
            }
            $this->whens = $this->watchers = [];

        });
    }

    public function when(callable $func) {
        if (isset($this->string)) {
            $func($this->error, $this->string);
        } else {
            $this->whens[] = $func;
        }
        return $this;
    }

    public function subscribe(callable $func) {
        if (!isset($this->string)) {
            $this->watchers[] = $func;
        }
    }
}

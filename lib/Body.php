<?php declare(strict_types = 1);

namespace Aerys;

use Amp\{ Internal\Producer, Observable, Observer, Postponed };

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
    use Producer;

    public function __construct(Observable $observable) {
        $observable->subscribe(function ($data) {
            return $this->emit($data);
        });
        
        parent::__construct($observable); // DO NOT MOVE - preserve order in which things happen
        
        $observable->when(function($e, $result) {
            if ($e) {
                $this->fail($e);
                return;
            }
            
            $when = static function ($e, $bool) use (&$continue) {
                $continue = $bool;
            };
            $string = "";
            $this->next()->when($when);
            while ($continue) {
                $string .= $this->getCurrent();
                $this->next()->when($when);
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
            
            $this->resolve($string);
        });
    }
}
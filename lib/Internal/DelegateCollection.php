<?php

namespace Aerys\Internal;

use Aerys\Request;
use Aerys\Responder;
use Aerys\Response;
use Amp\Coroutine;
use Amp\Promise;
use Amp\Success;
use const Aerys\HTTP_STATUS;
use function Aerys\makeGenericBody;

class DelegateCollection implements Responder {
    /** @var \Aerys\Delegate[] */
    private $delegates;

    public function __construct(array $delegates) {
        $this->delegates = $delegates;
    }

    public function respond(Request $request): Promise {
        return new Coroutine($this->delegate($request));
    }

    private function delegate(Request $request): \Generator {
        foreach ($this->delegates as $delegate) {
            $result = yield $delegate->delegate($request);

            if ($result === null) {
                continue;
            }

            if (!$result instanceof Response) {
                throw new \Error(
                    \sprintf("Delegates must resolve to null or an instance of ", Response::class)
                );
            }

            return $result;
        }

        return yield $this->error($request);
    }

    protected function error(Request $request): Promise {
        $status = HTTP_STATUS["NOT_FOUND"];
        return new Success(new Response\HtmlResponse(makeGenericBody($status), [], $status));
    }
}

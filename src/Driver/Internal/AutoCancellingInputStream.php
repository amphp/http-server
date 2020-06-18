<?php

namespace Amp\Http\Server\Driver\Internal;

use Amp\ByteStream\InputStream;
use Amp\CancellationTokenSource;
use Amp\Promise;

/** @internal */
final class AutoCancellingInputStream implements InputStream
{
    private $body;
    private $bodyCancellation;
    private $successfulEnd = false;

    public function __construct(InputStream $body, CancellationTokenSource $bodyCancellation)
    {
        $this->body = $body;
        $this->bodyCancellation = $bodyCancellation;
    }

    public function read(): Promise
    {
        $promise = $this->body->read();
        $promise->onResolve(function ($error, $value) {
            if ($value === null && $error === null) {
                $this->successfulEnd = true;
            }
        });

        return $promise;
    }

    public function __destruct()
    {
        if (!$this->successfulEnd) {
            $this->bodyCancellation->cancel();
        }
    }
}

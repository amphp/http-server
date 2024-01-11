<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal\Http3;

final class Http3ConnectionException extends \Exception
{
    public function __construct(
        string $message,
        Http3Error $code,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code->value, $previous);
    }
}

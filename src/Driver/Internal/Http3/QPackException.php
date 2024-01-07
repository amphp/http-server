<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal\Http3;

class QPackException extends \Exception
{
    public function __construct(public Http3Error $error, $message = null)
    {
        parent::__construct($message ?? "Encoding error of type: " . $error->name);
    }
}

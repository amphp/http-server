<?php declare(strict_types=1);

namespace Amp\Http\Server;

final class MissingAttributeError extends \Error
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}

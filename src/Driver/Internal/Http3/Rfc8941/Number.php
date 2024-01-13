<?php

namespace Amp\Http\Server\Driver\Internal\Http3\Rfc8941;

/**
 * @psalm-import-type Rfc8941Parameters from \Amp\Http\Server\Driver\Internal\Http3\Rfc8941
 * @property-read int|float $item
 */
class Number extends Item
{
    /**
     * @psalm-param Rfc8941Parameters $parameters
     */
    public function __construct(int|float $item, array $parameters)
    {
        parent::__construct($item, $parameters);
    }
}

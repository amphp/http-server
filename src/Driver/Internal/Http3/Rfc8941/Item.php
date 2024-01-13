<?php

namespace Amp\Http\Server\Driver\Internal\Http3\Rfc8941;

/**
 * @psalm-template InnerList
 * @psalm-import-type Rfc8941Parameters from \Amp\Http\Server\Driver\Internal\Http3\Rfc8941
 */
class Item
{
    /**
     * @psalm-param int|float|string|bool|InnerList $item
     * @psalm-param Rfc8941Parameters $parameters
     */
    protected function __construct(public readonly int|float|string|bool|array $item, public readonly array $parameters)
    {
    }
}

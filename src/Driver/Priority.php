<?php

namespace Amp\Http\Server\Driver;

use Amp\Promise;
use function Amp\call;

final class Priority
{
    /** @see https://tools.ietf.org/html/rfc7540#section-5.3.5 */
    public const DEFAULT_WEIGHT = 16;

    /** @var int */
    private $id;

    /** @var int */
    private $weight = self::DEFAULT_WEIGHT;

    /** @var int */
    private $parent = 0;

    /** @var bool */
    private $exclusive = false;

    /** @var callable[] */
    private $onUpdate = [];

    public function __construct(int $id)
    {
        $this->id = $id;
    }

    public function onUpdate(callable $onUpdate): void
    {
        $this->onUpdate[] = $onUpdate;

        if ($this->weight !== self::DEFAULT_WEIGHT || $this->parent !== 0 || $this->exclusive) {
            Promise\rethrow(call($onUpdate, $this->id, $this->weight, $this->parent, $this->exclusive));
        }
    }

    public function update(int $weight, int $parent, bool $exclusive): void
    {
        $this->weight = $weight;
        $this->parent = $parent;
        $this->exclusive = $exclusive;

        foreach ($this->onUpdate as $onUpdate) {
            Promise\rethrow(call($onUpdate, $this->id, $this->weight, $this->parent, $this->exclusive));
        }
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return int
     */
    public function getWeight(): int
    {
        return $this->weight;
    }

    /**
     * @return int
     */
    public function getParent(): int
    {
        return $this->parent;
    }

    /**
     * @return bool
     */
    public function isExclusive(): bool
    {
        return $this->exclusive;
    }
}

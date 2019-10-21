<?php

namespace Amp\Http\Server\Driver;

final class TimeoutCache implements \IteratorAggregate
{
    /** @var int[] */
    private $expirationTimes = [];

    /** @var int[] Client IDs recently updated. */
    private $updates = [];

    /**
     * @param int $id Client ID.
     *
     * @return int|null Expiration time if client ID was found in the cache, null if not found.
     */
    public function getExpirationTime(int $id): ?int
    {
        return $this->expirationTimes[$id] ?? null;
    }

    /**
     * Renews the timeout for the given ID.
     *
     * @param int $id Client ID.
     * @param int $expiresAt New expiration time.
     */
    public function renew(int $id, int $expiresAt): void
    {
        if ($expiresAt > ($this->expirationTimes[$id] ?? 0)) {
            $this->updates[$id] = $expiresAt;
        }
    }

    /**
     * Clears the timeout for the given ID, removing it completely from the list.
     *
     * @param int $id
     */
    public function clear(int $id): void
    {
        unset($this->expirationTimes[$id], $this->updates[$id]);
    }

    /**
     * @return \Iterator Unmodifiable iterator over all IDs in the cache, starting with oldest.
     */
    public function getIterator(): \Iterator
    {
        foreach ($this->updates as $id => $expiration) {
            unset($this->expirationTimes[$id]);
            $this->expirationTimes[$id] = $expiration;
        }

        $this->updates = [];

        return yield from $this->expirationTimes;
    }
}

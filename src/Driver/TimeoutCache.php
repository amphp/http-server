<?php

namespace Amp\Http\Server\Driver;

use cash\LRUCache;

final class TimeoutCache implements \IteratorAggregate
{
    /** @var LRUCache */
    private $cache;

    /** @var int[] Client IDs recently updated. */
    private $updates = [];

    public function __construct()
    {
        // Maybe we do need our own LRU-cache implementation?
        $this->cache = new class(\PHP_INT_MAX) extends LRUCache implements \IteratorAggregate {
            public function getIterator(): \Iterator
            {
                yield from $this->data;
            }
        };
    }

    /**
     * Renews the timeout for the given ID.
     *
     * @param int $id
     * @param int $expiresAt New expiration time.
     */
    public function renew(int $id, int $expiresAt): void
    {
        $this->updates[$id] = $expiresAt;
    }

    /**
     * Clears the timeout for the given ID, removing it completely from the list.
     *
     * @param int $id
     */
    public function clear(int $id): void
    {
        unset($this->updates[$id]);
        $this->cache->remove($id);
    }

    /**
     * @return \Iterator Unmodifiable iterator over all IDs in the cache, starting with oldest.
     */
    public function getIterator(): \Iterator
    {
        foreach ($this->updates as $id => $timeout) {
            $this->cache->put($id, $timeout);
        }

        $this->updates = [];

        return $this->cache->getIterator();
    }
}

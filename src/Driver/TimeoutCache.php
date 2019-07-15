<?php

namespace Amp\Http\Server\Driver;

use cash\LRUCache;

final class TimeoutCache implements \IteratorAggregate
{
    /** @var LRUCache */
    private $cache;

    /** @var int Number of seconds to add to the current time when renewing the timeout. */
    private $timeout;

    /** @var int[] Client IDs recently updated. */
    private $updates = [];

    /** @var int Current timestamp. */
    private $now;

    /**
     * @param TimeReference $timeReference
     * @param int           $timeout Number of seconds to add when renewing a timeout.
     */
    public function __construct(TimeReference $timeReference, int $timeout)
    {
        // Maybe we do need our own LRU-cache implementation?
        $this->cache = new class(\PHP_INT_MAX) extends LRUCache implements \IteratorAggregate {
            public function getIterator(): \Iterator
            {
                yield from $this->data;
            }
        };

        $this->timeout = $timeout;

        $timeReference->onTimeUpdate(function (int $timestamp) {
            $this->now = $timestamp;
        });
    }

    /**
     * Renews the timeout for the given ID.
     *
     * @param int $id
     */
    public function renew(int $id): void
    {
        $this->updates[$id] = $this->now + $this->timeout;
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

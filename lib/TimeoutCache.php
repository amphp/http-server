<?php

namespace Aerys;

use cash\LRUCache;

class TimeoutCache implements \IteratorAggregate {
    /** @var \cash\LRUCache */
    private $cache;

    /** @var int */
    private $time;

    /** @var \Aerys\TimeReference */
    private $timeReference;

    /**
     * @param \Aerys\TimeReference $timeReference
     * @param int $timeout Number of seconds to add when renewing a timeout.
     */
    public function __construct(TimeReference $timeReference, int $timeout) {
        // Maybe we do need our own LRU-cache implementation?
        $this->cache = new class(\PHP_INT_MAX) extends LRUCache implements \IteratorAggregate {
            public function getIterator(): \Iterator {
                foreach ($this->data as $key => $data) {
                    yield $key => $data;
                }
            }
        };

        $this->time = $timeout;
        $this->timeReference = $timeReference;
    }

    /**
     * Renews the timeout for the given ID.
     *
     * @param int $id
     */
    public function renew(int $id) {
        $timeoutAt = $this->timeReference->getCurrentTime() + $this->time;
        $this->cache->put($id, $timeoutAt);
    }

    /**
     * Clears the timeout for the given ID, removing it completely from the list.
     *
     * @param int $id
     */
    public function clear(int $id) {
        $this->cache->remove($id);
    }

    /**
     * @return \Iterator Unmodifiable iterator over all IDs in the cache, starting with oldest.
     */
    public function getIterator(): \Iterator {
        return $this->cache->getIterator();
    }
}

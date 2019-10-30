<?php

namespace Amp\Http\Server\Driver;

final class TimeoutCache
{
    /** @var object[] */
    private $data = [];

    /** @var int[] */
    private $pointers = [];

    /**
     * @param int $id Client ID.
     *
     * @return int|null Expiration time if client ID was found in the cache, null if not found.
     */
    public function getExpirationTime(int $id): ?int
    {
        if (!isset($this->pointers[$id])) {
            return null;
        }

        return $this->data[$this->pointers[$id]]->expiration;
    }

    /**
     * Renews the timeout for the given ID.
     *
     * @param int $id        Client ID.
     * @param int $expiresAt New expiration time.
     */
    public function update(int $id, int $expiresAt): void
    {
        if (isset($this->pointers[$id])) {
            $node = $this->pointers[$id];
            $entry = $this->data[$node];

            // Do not update tree structure if time is the same or less than the last time.
            if ($entry->expiration >= $expiresAt) {
                return;
            }

            $entry->expiration = $expiresAt;
            $this->rebuild($node, false);
            return;
        }

        $entry = new class {
            public $id;
            public $expiration;
        };

        $entry->id = $id;
        $entry->expiration = $expiresAt;

        $node = \count($this->data);
        $this->data[$node] = $entry;
        $this->pointers[$id] = $node;

        while ($node !== 0 && $entry->expiration < $this->data[$parent = ($node - 1) >> 1]->expiration) {
            $temp = $this->data[$parent];
            $this->data[$node] = $temp;
            $this->pointers[$temp->id] = $node;

            $this->data[$parent] = $entry;
            $this->pointers[$id] = $parent;

            $node = $parent;
        }
    }

    /**
     * @param int $id
     */
    public function clear(int $id): void
    {
        if (!isset($this->pointers[$id])) {
            return;
        }

        $this->rebuild($this->pointers[$id], true);
    }

    /**
     * Deletes and returns the Watcher on top of the heap. Time complexity: O(log(n)).
     *
     * @param int $now Return a Client ID only if the expiration is less than or equal to $now.
     *
     * @return  int|null Client ID removed, or null if no client has expired.
     */
    public function extract(int $now): ?int
    {
        if (empty($this->data) || $this->data[0]->expiration > $now) {
            return null;
        }

        return $this->rebuild(0, true);
    }

    /**
     * @param int  $node   Rebuild the data array from the given node downward.
     * @param bool $remove Remove the given node from the data array if true.
     *
     * @return int
     */
    private function rebuild(int $node, bool $remove): int
    {
        $data = $this->data[$node];
        $length = \count($this->data);

        if ($remove) {
            --$length;
            $left = $this->data[$node] = $this->data[$length];
            $this->pointers[$left->id] = $node;
            unset($this->data[$length], $this->pointers[$data->id]);
        }

        while (($child = ($node << 1) + 1) < $length) {
            if ($this->data[$child]->expiration < $this->data[$node]->expiration
                && ($child + 1 >= $length || $this->data[$child]->expiration < $this->data[$child + 1]->expiration)
            ) {
                // Left child is less than parent and right child.
                $swap = $child;
            } elseif ($child + 1 < $length && $this->data[$child + 1]->expiration < $this->data[$node]->expiration) {
                // Right child is less than parent and left child.
                $swap = $child + 1;
            } else { // Left and right child are greater than parent.
                break;
            }

            $left = $this->data[$node];
            $right = $this->data[$swap];

            $this->data[$node] = $right;
            $this->pointers[$right->id] = $node;

            $this->data[$swap] = $left;
            $this->pointers[$left->id] = $swap;

            $node = $swap;
        }

        return $data->id;
    }
}

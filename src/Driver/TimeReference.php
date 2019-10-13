<?php

namespace Amp\Http\Server\Driver;

interface TimeReference
{
    /**
     * @return int
     */
    public function getCurrentTime(): int;

    /**
     * @return string
     */
    public function getCurrentDate(): string;

    /**
     * Add a callback to invoke each time the time context updates.
     *
     * Callbacks are invoked with two parameters: currentTime and currentDate.
     *
     * Callbacks SHOULD NOT throw. Any errors will bubble up to the event loop.
     *
     * @param callable $callback
     *
     * @return string Identifier that can be used to cancel the update callback.
     */
    public function onTimeUpdate(callable $callback): string;

    /**
     * Removes the callback with the given identifier.
     *
     * @param string $id
     *
     * @throws \Error If the identifier does not exist.
     */
    public function cancelTimeUpdate(string $id): void;
}

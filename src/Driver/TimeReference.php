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
     */
    public function onTimeUpdate(callable $callback): void;
}

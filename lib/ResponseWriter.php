<?php

namespace Aerys;

/**
 * A Responder writes normalized HTTP responses to endpoint clients.
 *
 * Servers use a composed ResponderFactory to generate responders from a MessageCycle
 * object and an array of header/option settings.
 */
interface ResponseWriter {
    const COMPLETED = 0;
    const FUTURE = 1;
    const FAILED = -1;

    /**
     * Writes composed PendingResponse objects to their associated destination stream
     *
     * Implementations MUST return one of three values:
     *
     *  - Responder::COMPLETED (int) 0
     *  - Responder::FAILED (int) -1
     *  - An instance of Alert\Future
     *
     * If the write completes (or errors out) on the first call the COMPLETED/FAILED integer
     * constants are appropriate. All other results MUST return an Alert\Future instance which
     * may be resolved with a success or failure result at some point in the future when the
     * write operation completes or terminates from an error.
     */
    public function writeResponse();
}

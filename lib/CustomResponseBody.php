<?php

namespace Aerys;

use Alert\Reactor;

interface CustomResponseBody {

    /**
     * Returns the integer content length of the entity body (if available)
     *
     * An integer >= zero indicates the content length of the entity. If the length is unknown
     * implementors MUST return -1. In the event of an unknown length the server will stream
     * the body to the client in a manner appropriate for the HTTP protocol specified in the
     * original request.
     *
     * @return integer
     */
    public function getContentLength();

    /**
     * Generate a custom responder capable of writing the PendingResponse to the client.
     *
     * @return \Aerys\ResponseWriter
     */
    public function getResponseWriter(PendingResponse $pr);
}

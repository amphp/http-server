<?php

namespace Aerys;

interface ResponseWriter {
    /**
     * Return TRUE to indicate the response was written completely and that the client should
     * be closed. Return FALSE to indicate the response was written and the client should NOT be
     * closed (keep-alive). If the response cannot be written in full on the first invocation a
     * Future should be returned that is eventually resolved with TRUE/FALSE according to the
     * write result.
     *
     * @return bool|Alert\Future
     */
    public function writeResponse();
    
    /**
     * @TODO Add methods for accessing data regarding the written response, e.g.:
     *
     * public function getWrittenStatusCode();
     * public function getWrittenByteCount();
     * public function shouldCloseSocket();
     */
}

<?php

namespace Aerys;

interface ResponseWriterCustom extends ResponseWriter {
    /**
     * Prepare the writer with all data needed to manually output the $subject while adhering
     * to the current server configuration
     *
     * @param Aerys\ResponseWriterSubject $subject
     */
    public function prepareResponse(ResponseWriterSubject $subject);
}

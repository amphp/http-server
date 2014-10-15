<?php

namespace Aerys;

interface Responder {
    /**
     * Prepare the Responder
     *
     * @param Aerys\ResponderStruct $responderStruct
     */
    public function prepare(ResponderStruct $responderStruct);

    /**
     * Write the prepared response
     *
     * @return \Amp\Promise Returns a promise that resolves to TRUE if the connection should be
     *                      closed and FALSE if not.
     */
    public function write();
}

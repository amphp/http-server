<?php

namespace Aerys\Handlers\Websocket;

class Client {
    
    private $sessionFacade;
    
    function __construct(SessionFacade $sessionFacade) {
        $this->sessionFacade = $sessionFacade;
    }
    
    /**
     * Send UTF-8 text to the client
     * 
     * @param mixed|[string|resource|Traversable] $data
     * @return void
     */
    function sendText($data) {
        if (!($data || $data === '0')) {
            return;
        }
        
        $this->sessionFacade->send($data, Frame::OP_TEXT);
    }
    
    /**
     * Send binary data to the client
     * 
     * @param mixed|[string|resource|Traversable] $data
     * @return void
     */
    function sendBinary($data) {
        if (!($data || $data === '0')) {
            return;
        }
        
        $this->sessionFacade->send($data, Frame::OP_BIN);
    }
    
    /**
     * Gracefully close the client connection
     * 
     * @param int $code A valid closing status code in the range [1000-4999]
     * @param string $reason Optional close information (125 bytes max)
     * @return void
     */
    function close($code = Codes::NORMAL_CLOSE, $reason = '') {
        $this->sessionFacade->close($code, $reason);
    }
    
    /**
     * Retrieve the ASGI request environment used to open the websocket connection
     * 
     * @return array
     */
    function getEnvironment() {
        return $this->sessionFacade->getEnvironment();
    }
    
    /**
     * Retrieve aggregate IO statistics for the current session
     * 
     * The returned array contains the following keys:
     * 
     * - bytesRead
     * - bytesWritten
     * - msgReadCount
     * - msgWriteCount
     * - frameReadCount
     * - frameWriteCount
     * 
     * The byte totals only count payload data; frame header bytes are not included in the total.
     * 
     * @return array
     */
    function getStats() {
        return $this->sessionFacade->getStats();
    }
    
}


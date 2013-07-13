<?php

namespace Aerys\Handlers\Websocket;

class Client {
    
    private $handler;
    private $session;
    
    function __construct(WebsocketHandler $handler, ClientSession $session) {
        $this->handler = $handler;
        $this->session = $session;
    }
    
    /**
     * Send UTF-8 text data to the client
     * 
     * @param mixed [string|resource] $data UTF-8 string
     * @param callable Optional callback to invoke on send completion
     * @return void
     */
    function sendText($data, callable $afterSend = NULL) {
        if ($data || $data === '0') {
            $this->handler->broadcast($this->session, Frame::OP_TEXT, $data, $afterSend);
        }
    }
    
    /**
     * Send binary data to the client
     * 
     * @param mixed [string|resource] $data Binary data string
     * @param callable Optional callback to invoke on send completion
     * @return void
     */
    function sendBinary($data, callable $afterSend = NULL) {
        if ($data || $data === '0') {
            $this->handler->broadcast($this->session, Frame::OP_BIN, $data, $afterSend);
        }
    }
    
    /**
     * Gracefully close the client connection
     * 
     * @param int $code A valid closing status code in the range [1000-4999]
     * @param string $reason Optional close information (125 bytes max)
     * @return void
     */
    function close($code = Codes::NORMAL_CLOSE, $reason = '') {
        $this->handler->close($this->session, $code, $reason);
    }
    
    /**
     * Retrieve the ASGI request environment used to originate the client connection
     * 
     * @return array
     */
    function getEnvironment() {
        return $this->session->asgiEnv;
    }
    
    /**
     * Retrieve aggregate IO statistics for the current client session
     * 
     * @return array
     */
    function getStats() {
        return [
            'dataBytesRead'     => $this->session->dataBytesRead,
            'dataBytesSent'     => $this->session->dataBytesSent,
            'dataFramesRead'    => $this->session->dataFramesRead,
            'dataFramesSent'    => $this->session->dataFramesSent,
            'dataMessagesRead'  => $this->session->dataMessagesRead,
            'dataMessagesSent'  => $this->session->dataMessagesSent,
            'controlBytesRead'  => $this->session->controlBytesRead,
            'controlBytesSent'  => $this->session->controlBytesSent,
            'controlFramesRead' => $this->session->controlFramesRead,
            'controlFramesSent' => $this->session->controlFramesSent,
            'dataLastReadAt'    => $this->session->dataLastReadAt,
            'connectedAt'       => $this->session->connectedAt,
            'totalClientCount'  => $this->handler->count()
        ];
    }
    
}


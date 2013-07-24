<?php

use Aerys\Mods\Protocol\ModProtocol,
    Aerys\Mods\Protocol\ProtocolHandler;

class LineFeedProtocolHandler implements ProtocolHandler {
    
    private $modProtocol;
    private $chatMediator;
    private $socketIdMap = [];
    private $chatIdMap = [];
    private $socketParseBuffers = [];
    
    function __construct(ModProtocol $modProtocol, ChatMediator $chatMediator) {
        $this->modProtocol = $modProtocol;
        $this->chatMediator = $chatMediator;
        
        $chatMediator->subscribe('message', function($authorId, $msg) {
            $this->onMediatorMessage($authorId, $msg);
        });
    }
    
    // ------------------------ REQUIRED PROTOCOL HANDLER INTERFACE METHODS ----------------------//
    
    function negotiate($rejectedHttpMessage, array $socketInfo) {
        $msg = trim($rejectedHttpMessage);
        
        // Import the connection only if the client sent "line-feed" as its "HTTP request"
        return !strcasecmp($msg, 'line-feed');
    }
    
    function onOpen($socketId, $openingMsg, array $socketInfo) {
        $chatId = $this->chatMediator->registerUser();
        
        $this->socketIdMap[$socketId] = $chatId;
        $this->chatIdMap[$chatId] = $socketId;
        $this->socketParseBuffers[$socketId] = '';
    }
    
    function onData($socketId, $data) {
        if ($this->socketParseBuffers[$socketId]) {
            $data = $this->socketParseBuffers[$socketId] . $data;
        }
        
        $chatId = $this->socketIdMap[$socketId];
        
        while (($eolPos = strpos($data, "\n")) !== FALSE) {
            $line = trim(substr($data, 0, $eolPos));
            $data = substr($data, $eolPos + 2);
            
            if ($line !== '') {
                $this->chatMediator->broadcast($chatId, $line);
            }
        }
        
        if ($data !== FALSE) {
            $this->socketParseBuffers[$socketId] = $data;
        }
    }
    
    function onTimeout($socketId) {
        // not implemented in this example because we don't care
        // about timing out clients due to inactivity
    }
    
    function onClose($socketId, $closeReason) {
        $chatId = $this->socketIdMap[$socketId];
        
        unset(
            $this->socketIdMap[$socketId],
            $this->chatIdMap[$chatId],
            $this->socketParseBuffers[$socketId]
        );
        
        $this->chatMediator->disconnect($chatId);
    }
    
    // ---------------------- LISTEN FOR NEW CHAT MESSAGE BROADCASTS -----------------------------//
    
    private function onMediatorMessage($authorId, $msg) {
        $recipients = $this->chatIdMap;
        unset($recipients[$authorId]);
        
        $msg .= "\r\n";
        
        foreach ($recipients as $socketId) {
            $this->modProtocol->write($socketId, $msg);
        }
    }
    
}

<?php

namespace Aerys\Handlers\Websocket;

use Amp\Reactor,
    Aerys\Handlers\Websocket\Io\FrameParser,
    Aerys\Handlers\Websocket\Io\FrameWriter,
    Aerys\Handlers\Websocket\Io\StreamFactory,
    Aerys\Handlers\Websocket\Io\ParseException,
    Aerys\Handlers\Websocket\Io\ResourceException;

class Session {
    
    const CLOSE_NONE = 0b00;
    const CLOSE_RCVD = 0b01;
    const CLOSE_SENT = 0b10;
    
    const DEBUG_INBOUND = 0;
    const DEBUG_OUTBOUND = 1;
    
    private $manager;
    private $parser;
    private $writer;
    private $endpoint;
    private $asgiEnv;
    private $streamFactory;
    private $streamQueue = [];
    private $currentStream;
    
    private $closeState = self::CLOSE_NONE;
    private $closeCode;
    private $closeReason;
    
    private $pendingPingPayloads = [];
    
    private $autoFrameSize = 32768;
    private $ioGranularity = 8192;
    private $queuedPingLimit = 3;
    private $isHeartbeatEnabled = TRUE;
    private $debugMode = FALSE;
    
    private $stats = [
        'bytesRead' => 0,
        'bytesWritten' => 0,
        'msgReadCount' => 0,
        'msgWriteCount' => 0,
        'frameReadCount' => 0,
        'frameWriteCount' => 0
    ];
    
    function __construct(
        SessionManager $manager,
        FrameParser $parser,
        FrameWriter $writer,
        Endpoint $endpoint,
        array $asgiEnv,
        StreamFactory $streamFactory,
        ClientFactory $clientFactory
    ) {
        $this->manager = $manager;
        $this->parser = $parser;
        $this->writer = $writer;
        $this->endpoint = $endpoint;
        $this->asgiEnv = $asgiEnv;
        $this->streamFactory = $streamFactory;
        
        $this->client = $clientFactory(new SessionFacade($this));
    }
    
    function setOptions(EndpointOptions $opts) {
        $this->autoFrameSize = $opts->getAutoFrameSize();
        $this->ioGranularity = $opts->getIoGranularity();
        $this->queuedPingLimit = $opts->getQueuedPingLimit();
        $this->isHeartbeatEnabled = (bool) $opts->getHeartbeatPeriod();
        $this->debugMode = $opts->getDebugMode();
        
        $this->parser->setGranularity($this->ioGranularity);
        $this->parser->setMsgSwapSize($opts->getMsgSwapSize());
        $this->parser->setMaxFrameSize($opts->getMaxFrameSize());
        $this->parser->setMaxMsgSize($opts->getMaxMsgSize());
        
        $this->writer->setGranularity($this->ioGranularity);
    }
    
    function open() {
        try {
            $this->endpoint->onOpen($this->client);
        } catch (\Exception $e) {
            @fwrite($this->asgiEnv['ASGI_ERROR'], $e);
            $this->addCloseFrame(Codes::UNEXPECTED_SERVER_ERROR);
        }
    }
    
    function read($sock, $trigger) {
        $isTimeout = ($trigger == Reactor::TIMEOUT);
        if ($isTimeout && $this->isHeartbeatEnabled) {
            return $this->addPingFrame();
        } elseif ($isTimeout) {
            return;
        }
        
        try {
            if (!$parsedMsgArr = $this->parser->parse()) {
                return;
            }
        } catch (ResourceException $e) {
            return $this->disconnect(Codes::ABNORMAL_CLOSE, 'Client went away');
        } catch (ParseException $e) {
            return $this->addCloseFrame(Codes::PROTOCOL_ERROR);
        } catch (PolicyException $e) {
            return $this->addCloseFrame(Codes::POLICY_VIOLATION, $e->getMessage());
        } catch (\Exception $e) {
            @fwrite($this->asgiEnv['ASGI_ERROR'], $e);
            return $this->addCloseFrame(Codes::UNEXPECTED_SERVER_ERROR);
        }
        
        if ($this->debugMode) {
            $this->outputDebugInfo($parsedMsgArr, self::DEBUG_INBOUND);
        }
        
        list($opcode, $payload, $length, $componentFrames) = $parsedMsgArr;
        
        switch ($opcode) {
            case Frame::OP_PING:
                $this->receivePingFrame($payload);
                break;
            case Frame::OP_PONG:
                $this->receivePongFrame($payload);
                break;
            case Frame::OP_CLOSE:
                $this->receiveCloseFrame($payload);
                break;
            default:
                $this->stats['msgReadCount']++;
                
                foreach ($componentFrames as $frameStruct) {
                    $this->stats['bytesRead'] += $frameStruct['length'];
                    $this->stats['frameReadCount']++;
                }
                
                $msg = new Message($opcode, $payload, $length, $componentFrames);
                $this->receiveMessage($msg);
        }
    }
    
    /**
     * According to RFC 6455 Section 5.5.2: 'A Ping frame MAY include "Application data"'
     * 
     * However, as of the time of this writing some browsers (I'm looking at you, Chrome) will not
     * respond with a PONG to PING frames that don't carry application data in the frame payload.
     * To correct for this, we ensure there is always a payload attached to each outbound PING.
     * 
     * @link http://tools.ietf.org/html/rfc6455#section-5.5.2
     */
    function addPingFrame() {
        $data = pack('S', rand(0, 32768));
        $pingFrame = new Frame(Frame::FIN, Frame::RSV_NONE, Frame::OP_PING, $data);
        $this->addFrame($pingFrame);
    }
    
    private function disconnect($code = NULL, $reason = NULL) {
        $code = ($code === NULL) ? $this->closeCode : $code;
        $reason = ($reason === NULL) ? $this->closeReason : substr($reason, 0, 125);
        
        $this->manager->close($this);
        
        try {
            $this->endpoint->onClose($this->client, $code, $reason);
        } catch (\Exception $e) {
            @fwrite($this->asgiEnv['ASGI_ERROR'], $e);
        }
    }
    
    function addCloseFrame($code, $reason = '') {
        $this->closeCode = $code ?: Codes::NONE;
        $this->closeReason = substr($reason, 0, 125);
        
        $code = $code ? pack('n', $code) : ''; 
        
        $payload = $code . $this->closeReason;
        $closeFrame = new Frame(Frame::FIN, Frame::RSV_NONE, Frame::OP_CLOSE, $payload);
        
        $this->addFrame($closeFrame);
    }
    
    private function receivePingFrame($payload) {
        $pongFrame = new Frame(Frame::FIN, Frame::RSV_NONE, Frame::OP_PONG, $payload);
        $this->addFrame($pongFrame);
    }
    
    function addFrame(Frame $frame) {
        if ($this->closeState ^ self::CLOSE_SENT) {
            $this->writer->enqueue($frame);
            $this->manager->autowrite($this);
        }
    }
    
    private function receivePongFrame($payload) {
        for ($i=count($this->pendingPingPayloads)-1; $i>=0; $i--) {
            if ($this->pendingPingPayloads[$i] == $payload) {
                $this->pendingPingPayloads = array_slice($this->pendingPingPayloads, $i+1);
                break;
            }
        }
    }
    
    private function receiveCloseFrame($payload) {
        $this->manager->shutdownRead($this);
        
        $this->closeState = $this->closeState | self::CLOSE_RCVD;
        
        if ($this->closeState & self::CLOSE_SENT) {
            $this->disconnect();
        } else {
            list($code, $reason) = $this->parseClosePayload($payload);
            $this->addCloseFrame($code, $reason);
        }
    }
    
    private function parseClosePayload($payload) {
        if (!$payload || strlen($payload) < 2) {
            return ['', ''];
        }
        
        $code = unpack('nstatus', substr($payload, 0, 2))['status'];
        $code = filter_var($code, FILTER_VALIDATE_INT, ['options' => [
            'default' => Codes::NONE,
            'min_range' => 1000,
            'max_range' => 4999
        ]]);
        
        $reason = substr($payload, 2, 125);
        
        return [$code, $reason];
    }
    
    private function receiveMessage(Message $msg) {
        try {
            $this->endpoint->onMessage($this->client, $msg);
        } catch (\Exception $e) {
            @fwrite($this->asgiEnv['ASGI_ERROR'], $e);
            $this->addCloseFrame(Codes::UNEXPECTED_SERVER_ERROR);
        }
    }
    
    /**
     * @return bool Returns TRUE if all queued frame data has been written, FALSE otherwise
     */
    function write() {
        if ($this->closeState & self::CLOSE_SENT) {
            return TRUE;
        } elseif (!$frame = $this->writer->write()) {
            return FALSE;
        }
        
        if ($this->debugMode) {
            $this->outputDebugInfo($frame, self::DEBUG_OUTBOUND);
        }
        
        if ($this->currentStream) {
            $this->addNextFrameFromCurrentStream();
        } elseif ($this->streamQueue) {
            $this->currentStream = array_shift($this->streamQueue);
            $this->addNextFrameFromCurrentStream();
        }
        
        switch ($frame->getOpcode()) {
            case Frame::OP_CLOSE:
                $this->afterCloseWrite();
                return TRUE;
                break;
            case Frame::OP_PING:
                $this->afterPingWrite($frame->getPayload());
                break;
            default:
                $this->stats['frameWriteCount']++;
                $this->stats['bytesWritten'] += $frame->getLength();
                $this->stats['msgWriteCount'] += $frame->isFin();
        }
        
        return !$this->writer->canWrite();
    }
    
    /**
     * Frame stream operations may throw an exception if the filesystem borks or a userland
     * Iterator stream throws an exception. Stream iterator methods should always be wrapped inside
     * a try/catch to avoid breakage.
     */
    private function addNextFrameFromCurrentStream() {
        try {
            if (NULL === ($payload = $this->currentStream->current())) {
                return;
            }
            
            $this->currentStream->next();
            
            if ($isLastFrameInMsg = !$this->currentStream->valid()) {
                $fin = TRUE;
                $opcode = $this->currentStream->isBinary() ? Frame::OP_BIN : Frame::OP_TEXT;
                $this->currentStream = NULL;
            } else {
                $fin = FALSE;
                $opcode = Frame::OP_CONT;
            }
            
            $frame = new Frame(Frame::FIN, Frame::RSV_NONE, $opcode, $payload);
            $this->addFrame($frame);
            
        } catch (\Exception $e) {
            @fwrite($this->asgiEnv['ASGI_ERROR'], $e);
            $this->addCloseFrame(Codes::UNEXPECTED_SERVER_ERROR);
        }
    }
    
    private function afterCloseWrite() {
        $this->closeState = $this->closeState | self::CLOSE_SENT;
        
        if ($this->closeState & self::CLOSE_RCVD) {
            $this->disconnect();
        } else {
            $this->manager->awaitClose($this);
        }
    }
    
    private function afterPingWrite($payload) {
        $this->pendingPingPayloads[] = $payload;
        
        // Prevent naughty clients from never responding to PINGs and forcing the server to store
        // PING payloads ad infinitum
        if (count($this->pendingPingPayloads) > $this->queuedPingLimit) {
            $code = Codes::POLICY_VIOLATION;
            $reason = 'Exceeded unanswered PING limit';
            $this->addCloseFrame($code, $reason);
        }
    }
    
    /**
     * Frame stream operations may throw an exception if the filesystem borks or a userland
     * Iterator stream throws an exception. Stream iterator methods should always be wrapped inside
     * a try/catch to avoid breakage.
     */
    function addStreamData($data, $opcode) {
        try {
            $frameStream = $this->streamFactory->__invoke($data, $opcode);
            $frameStream->setAutoFrameSize($this->autoFrameSize);
            
            if ($this->currentStream) {
                $this->streamQueue[] = $frameStream;
            } else {
                $this->currentStream = $frameStream;
                $this->addNextFrameFromCurrentStream();
            }
        } catch (\Exception $e) {
            @fwrite($this->asgiEnv['ASGI_ERROR'], $e);
            $this->addCloseFrame(Codes::UNEXPECTED_SERVER_ERROR);
        }
    }
    
    function getAsgiEnv() {
        return $this->asgiEnv;
    }
    
    function getStats() {
        $stats = $this->stats;
        $stats['currentEndpointClients'] = $this->manager->count($this->asgiEnv['REQUEST_URI']);
        $stats['allClients'] = $this->manager->count();
        
        return $stats;
    }
    




    private function outputDebugInfo($data, $inOrOut) {
        return ($inOrOut == self::DEBUG_INBOUND)
            ? $this->debugInbound($data)
            : $this->debugOutbound($data);
    }
    
    private function debugInbound(array $parsedMsgArr) {
        $uri = $this->asgiEnv['REQUEST_URI'];
        
        foreach ($parsedMsgArr[3] as $frameStruct) {
            $length = $frameStruct['length'];
            $opcode = $this->getOpcodeName($frameStruct['opcode']);
            
            echo time(), " | RCVD | $opcode | $length | $uri\n";
        }
    }
    
    private function getOpcodeName($opcode) {
        switch ($opcode) {
            case Frame::OP_CONT:
                return 'CONT';
            case Frame::OP_TEXT:
                return 'TEXT';
            case Frame::OP_BIN:
                return 'BIN';
            case Frame::OP_CLOSE:
                return 'CLOSE';
            case Frame::OP_PING:
                return 'PING';
            case Frame::OP_PONG:
                return 'PONG';
            default:
                return 'UNKNOWN';
        }
    }
    
    private function debugOutbound(Frame $frame) {
        $uri = $this->asgiEnv['REQUEST_URI'];
        $length = $frame->getLength();
        $opcode = $this->getOpcodeName($frame->getOpcode());
        
        echo time(), " | SENT | $opcode | $length | $uri\n";
    }
    
}


<?php

namespace Aerys\Handlers\WorkerPool;

use Amp\Async\Frame,
    Amp\Async\FrameParser,
    Amp\Async\FrameWriter,
    Amp\Async\Dispatcher;

class Worker {
    
    private $parser;
    private $writer;
    private $chrCallResult;
    private $chrCallError;
    
    function __construct(FrameParser $parser, FrameWriter $writer) {
        $this->parser = $parser;
        $this->writer = $writer;
        
        $parser->throwOnEof(FALSE);
        
        $this->chrCallResult = chr(Dispatcher::CALL_RESULT);
        $this->chrCallError = chr(Dispatcher::CALL_ERROR);
    }
    
    function onReadable() {
        while ($frameArr = $this->parser->parse()) {
            $payload = $frameArr[3];
            
            $callId = substr($payload, 0, 4);
            $callCode = $payload[4];
            $procLen = ord($payload[5]);
            $procedure = substr($payload, 6, $procLen);
            $workload = substr($payload, $procLen + 6);
            
            try {
                $this->invokeProcedure($callId, $procedure, $workload);
            } catch (ResourceException $e) {
                throw $e;
            } catch (\Exception $e) {
                $payload = $callId . $this->chrCallError . $e->__toString();
                $frame = new Frame($fin = 1, $rsv = 0, Frame::OP_DATA, $payload);
                $this->send($frame);
            }
        }
    }
    
    private function invokeProcedure($callId, $procedure, $workload) {
        $asgiEnv = json_decode($workload, TRUE);
        
        $asgiEnv['ASGI_NON_BLOCKING'] = FALSE;
        $asgiEnv['ASGI_ERROR'] = STDERR;
        
        if ($asgiEnv['ASGI_INPUT']) {
            $asgiEnv['ASGI_INPUT'] = fopen($asgiEnv['ASGI_INPUT'], 'rb');
        }
        
        $asgiResponse = $procedure($asgiEnv);
        
        if ($asgiResponse[3] instanceof \Iterator) {
            
            list($status, $reason, $headers, $body) = $asgiResponse;
            
            $payload = $callId . $this->chrCallResult . json_encode([$status, $reason, $headers]);
            $frame = new Frame($isFin = 0, $rsv = 0, Frame::OP_DATA, $payload);
            $this->writer->writeAll($frame);
            
            while (TRUE) {
                $chunk = $body->current();
                $body->next();
                $isFin = (int) !$body->valid();
                
                if (NULL !== $chunk) {
                    $chunk = $callId . $this->chrCallResult . $chunk;
                    $frame = new Frame($isFin, $rsv = 0, Frame::OP_DATA, $chunk);
                    $this->writer->writeAll($frame);
                }
                
                if ($isFin) {
                    break;
                }
            }
            
        } elseif ($payload = json_encode($asgiResponse)) {
            $payload = $callId . $this->chrCallResult . $payload;
            $frame = new Frame($isFin = 1, $rsv = 0, Frame::OP_DATA, $payload);
            $this->writer->writeAll($frame);
        } else {
            throw new \RuntimeException(
                'Failed encoding ASGI response for transport'
            );
        }
    }
    
}

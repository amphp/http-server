<?php

use Aerys\Handlers\Websocket\EndpointOptions;

class EndpointOptionsTest extends PHPUnit_Framework_TestCase {
    
    /**
     * @dataProvider provideOptionValues
     */
    function testOptions($values, $expectations) {
        $opts = new EndpointOptions($values);
        
        $this->assertEquals($expectations['beforeHandshake'], $opts->getBeforeHandshake());
        $this->assertEquals($expectations['subprotocol'], $opts->getSubprotocol());
        $this->assertEquals($expectations['allowedOrigins'], $opts->getAllowedOrigins());
        $this->assertEquals($expectations['maxMsgSize'], $opts->getMaxMsgSize());
        $this->assertEquals($expectations['msgSwapSize'], $opts->getMsgSwapSize());
        $this->assertEquals($expectations['maxFrameSize'], $opts->getMaxFrameSize());
        $this->assertEquals($expectations['autoFrameSize'], $opts->getAutoFrameSize());
        $this->assertEquals($expectations['queuedPingLimit'], $opts->getQueuedPingLimit());
        $this->assertEquals($expectations['heartbeatPeriod'], $opts->getHeartbeatPeriod());
    }
    
    function provideOptionValues() {
        $return = [];
        
        // 0 ---------------------------------------------------------------------------------------
        
        $options = [
            'beforeHandshake'  => NULL,
            'subprotocol'      => NULL,
            'allowedOrigins'   => [],
            'msgSwapSize'      => 2097152,
            'maxFrameSize'     => 2097152,
            'maxMsgSize'       => 10485760,
            'autoFrameSize'    => 32768,
            'queuedPingLimit'  => 3,
            'heartbeatPeriod'  => 10,
        ];
        
        $expects = [
            'beforeHandshake'  => NULL,
            'subprotocol'      => NULL,
            'allowedOrigins'   => [],
            'msgSwapSize'      => 2097152,
            'maxFrameSize'     => 2097152,
            'maxMsgSize'       => 10485760,
            'autoFrameSize'    => 32768,
            'queuedPingLimit'  => 3,
            'heartbeatPeriod'  => 10,
        ];
        
        $return[] = [$options, $expects];
        
        // x ---------------------------------------------------------------------------------------
        
        return $return;
    }
}


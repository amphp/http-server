<?php

namespace Aerys\Test\Mods\Log;

use Aerys\Mods\Log\ModLog,
    Aerys\Server;

class ModLogTest extends \PHPUnit_Framework_TestCase {

    /**
     * @expectedException InvalidArgumentException
     */
    function testConstructorThrowsOnEmptyLogsArray() {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', NULL, [$reactor]);
        $mod = new ModLog($server, $logs = []);
    }

    function provideAfterResponseExpectations() {
        $return = [];

        $asgiEnv = [
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PORT' => '1337',
            'SERVER_PROTOCOL' => '1.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'REMOTE_PORT' => '46401',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'QUERY_STRING' => '',
            'CONTENT_TYPE' => '',
            'CONTENT_LENGTH' => '',
            'ASGI_VERSION' => '0.1',
            'REQUEST_URI_SCHEME' => 'http',
            'ASGI_INPUT' => '',
            'ASGI_ERROR' => '',
            'ASGI_CAN_STREAM' => '1',
            'ASGI_NON_BLOCKING' => '1',
            'ASGI_LAST_CHANCE' => '1',
            'HTTP_HOST' => '127.0.0.1:1337',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.28 (KHTML, like Gecko) Chrome/26.0.1397.2 Safari/537.28',
            'HTTP_REFERER' => 'http://www.google.com'
        ];

        $headers = "\r\nDate: " . gmdate('D, d M Y H:i:s') . ' UTC' .
            "\r\nContent-Length: 4" .
            "\r\nContent-Type: text/plain; charset=iso-8859-1";

        $asgiResponse = [
            $status = 200,
            $reason = 'OK',
            $headers,
            $body = 'test'
        ];

        // 0 ---------------------------------------------------------------------------------------
        $logs = [
            'php://output' => 'common'
        ];

        $expectedOutput =
            $asgiEnv['REMOTE_ADDR'] . ' - - [$$$time$$$] "' .
            $asgiEnv['REQUEST_METHOD'] . ' ' . $asgiEnv['REQUEST_URI'] . ' HTTP/' .
            $asgiEnv['SERVER_PROTOCOL'] . '" ' . $asgiResponse[0] . ' 4' . PHP_EOL;

        $return[] = [$logs, $asgiEnv, $asgiResponse, $expectedOutput];

        // x ---------------------------------------------------------------------------------------

        return $return;
    }

    /**
     * @dataProvider provideAfterResponseExpectations
     */
    function testAfterResponse($logs, $asgiEnv, $asgiResponse, $expectedOutput) {
        $reactor = $this->getMock('Alert\Reactor');
        $server = $this->getMock('Aerys\Server', ['getRequest', 'getResponse'], [$reactor]);

        $requestId = 42;

        $server->expects($this->once())
               ->method('getRequest')
               ->with($requestId)
               ->will($this->returnValue($asgiEnv));

        $server->expects($this->once())
               ->method('getResponse')
               ->with($requestId)
               ->will($this->returnValue($asgiResponse));

        $options = [
            'flushSize' => 1
        ];

        $mod = new ModLog($server, $logs, $options);

        $expectedOutput = str_replace('$$$time$$$', date('d/M/Y:H:i:s O'), $expectedOutput);
        $this->expectOutputString($expectedOutput);

        $mod->afterResponse($requestId);
    }

}

<?php

use Aerys\Engine\LibEventBase,
    Aerys\Handlers\Apm\Message,
    Aerys\Handlers\Apm\MessageParser;

require __DIR__ . '/autoload.php';

define('APM_VERSION', 1);
define('READ_TIMEOUT', 60000000);
stream_set_blocking(STDIN, FALSE);


/**
 * --- YOUR APP'S MAIN() FUNCTION ---
 */
 
function main(array $asgiEnv) {
    return [200, 'OK', [], '<html><body><h1>Hello, world.</h1></body></html>'];
}

/**
 * --- END USERLAND CODE ---
 */

function onMessage(array $msg) {
    list($type, $requestId, $asgiEnv) = $msg;
    
    $asgiEnv = $asgiEnv ? json_decode($asgiEnv, TRUE) : $asgiEnv;
    
    try {
        $asgiResponse = main($asgiEnv);
        $body = json_encode($asgiResponse);
        $length = strlen($body);
        $type = Message::RESPONSE;
    } catch (Exception $e) {
        $body = $e->getMessage();
        $length = strlen($body);
        $type = Message::ERROR;
    }
    
    echo pack(Message::HEADER_PACK_PATTERN, APM_VERSION, $type, $requestId, $length), $body;
}

$inputParser = (new MessageParser)->setOnMessageCallback('onMessage');
$eventBase = new LibEventBase;
$eventBase->onReadable(STDIN, function() use ($inputParser) {
    $input = fread(STDIN, 8192);
    $inputParser->parse($input);
}, READ_TIMEOUT);

$eventBase->run();


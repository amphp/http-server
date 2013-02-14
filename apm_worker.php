<?php

/**
 * apm_worker.php
 * 
 * To use this worker with the Aerys\Apm\ProcessManager:
 * 
 * ```php
 * <?php
 * // Assuming "php" is the path to the php executable on your system, "$dir" is the
 * // filesystem directory in which THIS file (apm_worker.php) lives on your system
 * // and "$appHandler" is the absolute path to the php file containing the application
 * // handler specifying a main(array $asgiEnv) function:
 * 
 * $cmd = 'php ' . $dir . '/apm_worker.php ' . $appHandler;
 * 
 * (new Aerys\ServerFactory)->createServer([[
 *   'listen' => '*:1337',
 *   'handler' => new Aerys\Apm\ProcessManager($cmd)
 * ]])->listen();
 * 
 * ?>
 * ```
 * 
 * @TODO Select appropriate event base by system lib availability
 */

use Aerys\Engine\LibEventBase,
    Aerys\Apm\Message,
    Aerys\Apm\MessageParser;

require __DIR__ . '/autoload.php';

define('APM_VERSION', 1);
define('READ_TIMEOUT', 60000000);
stream_set_blocking(STDIN, FALSE);



/**
 * Validate the handler as much as humanly possible
 */
 
if (!ini_get('register_argc_argv')) {
    trigger_error(
        '`register_argc_argv` must be enabled in php.ini to proceed',
        E_USER_ERROR
    );
    exit(1);
} elseif (!isset($argv[1])) {
    trigger_error(
        'No application handler specified',
        E_USER_ERROR
    );
    exit(1);
} elseif (!@include($argv[1])) {
    trigger_error(
        'Failed loading application handler script: ' . $argv[1],
        E_USER_ERROR
    );
    exit(1);
} elseif (!function_exists('main')) {
    trigger_error(
        'Application handler does not specify the requisite `main()` function',
        E_USER_ERROR
    );
    exit(1);
}

$mainReflection = new ReflectionFunction('main');
if (!$mainReflection->getParameters()) {
    trigger_error(
        'Application handler `main()` function must accept an ASGI environment param at Argument 1',
        E_USER_ERROR
    );
    exit(1);
}



/**
 * Fire up the event loop and wait for requests to arrive
 */

$inputParser = (new MessageParser)->setOnMessageCallback(function(array $msg) {
    list($type, $requestId, $asgiEnv) = $msg;
    
    if ($asgiEnv) {
        $asgiEnv = json_decode($asgiEnv, TRUE);
        $asgiEnv['ASGI_ERROR'] = STDERR;
    }
    
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
});

$eventBase = new LibEventBase;
$eventBase->onReadable(STDIN, function() use ($inputParser) {
    $input = fread(STDIN, 8192);
    $inputParser->parse($input);
}, READ_TIMEOUT);

$eventBase->run();


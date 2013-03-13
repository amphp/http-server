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

use Aerys\Apm\Message,
    Aerys\Apm\MessageParser,
    Aerys\Reactor\ReactorFactory;

require __DIR__ . '/autoload.php';

define('APM_VERSION', 1);
define('READ_TIMEOUT', 60000000);
stream_set_blocking(STDIN, FALSE);

error_reporting(E_ALL);
set_error_handler(function($errNo, $errStr, $errFile, $errLine) {
    if (!error_reporting()) {
        return;
    }
    
    switch ($errNo) {
        case 1:     $errType = 'E_ERROR'; break;
        case 2:     $errType = 'E_WARNING'; break;
        case 4:     $errType = 'E_PARSE'; break;
        case 8:     $errType = 'E_NOTICE'; break;
        case 256:   $errType = 'E_USER_ERROR'; break;
        case 512:   $errType = 'E_USER_WARNING'; break;
        case 1024:  $errType = 'E_USER_NOTICE'; break;
        case 2048:  $errType = 'E_STRICT'; break;
        case 4096:  $errType = 'E_RECOVERABLE_ERROR'; break;
        case 8192:  $errType = 'E_DEPRECATED'; break;
        case 16384: $errType = 'E_USER_DEPRECATED'; break;
        
        default:    $errType = 'PHP ERROR'; break;
    }
                
    $msg = "[$errType]: $errStr in $errFile on line $errLine" . PHP_EOL;
    
    throw new ErrorException($msg, $errNo);
});

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
        'Application controller MUST specify a `main()` function to return the application callable',
        E_USER_ERROR
    );
    exit(1);
} elseif (!(($app = main()) && is_callable($app))) {
    trigger_error(
        'Application controller `main()` function MUST return a valid callable',
        E_USER_ERROR
    );
    exit(1);
}



/**
 * Fire up the event loop and wait for requests to arrive
 */

$inputParser = (new MessageParser)->setOnMessageCallback(function(array $msg) use ($app) {
    list($type, $requestId, $asgiEnv) = $msg;
    
    if ($asgiEnv) {
        $asgiEnv = json_decode($asgiEnv, TRUE);
        $asgiEnv['ASGI_ERROR'] = STDERR;
        $asgiEnv['ASGI_INPUT'] = $asgiEnv['ASGI_INPUT'] ? fopen($asgiEnv['ASGI_INPUT'], 'r') : NULL;
    }
    
    ob_start();
    
    try {
        $asgiResponse = $app($asgiEnv);
        $body = json_encode($asgiResponse);
        $type = Message::RESPONSE;
    } catch (Exception $e) {
        $body = ($exMsg = $e->getMessage()) ? "<h4>$exMsg</h4>" : '';
        $body.= "<pre>$e</pre>";
        $type = Message::ERROR;
    }
    
    $length = strlen($body);
    
    if ($badOutput = ob_get_contents()) {
        fwrite(STDERR, '[E_SCRIPT_OUTPUT] ' . trim($badOutput) . PHP_EOL);
    }
    
    ob_end_clean();
    
    echo pack(Message::HEADER_PACK_PATTERN, APM_VERSION, $type, $requestId, $length), $body;
});

$reactor = (new ReactorFactory)->select();
$reactor->onReadable(STDIN, function() use ($inputParser) {
    $input = fread(STDIN, 8192);
    $inputParser->parse($input);
}, READ_TIMEOUT);

$reactor->run();


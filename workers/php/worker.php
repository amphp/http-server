<?php

use Amp\ReactorFactory,
    Amp\Async\FrameParser,
    Amp\Async\FrameWriter,
    Aerys\Handlers\WorkerPool\Worker;

date_default_timezone_set(ini_get('date.timezone') ?: 'UTC');

require dirname(dirname(__DIR__)) . '/autoload.php';

if (empty($argv[1])) {
    throw new RuntimeException(
        'No userland front controller argument present'
    );
} elseif (!@include($argv[1])) {
    throw new RuntimeException(
        'Failed including userland front controller file: ' . $argv[1]
    );
}


/**
 * Report ALL errors. Without this setting debugging problems in child processes can be a NIGHTMARE.
 * It's turned on after the userland include to override any error reporting settings therein.
 */
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');


/**
 * Write all errors to the main process's STDERR stream
 */
set_error_handler(function($errNo, $errStr, $errFile, $errLine) {
    if (error_reporting()) {
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
        
        fwrite(STDERR, $msg);
        exit(1);
    }
});


/**
 * Send information about an uncaught worker exception to the main process STDERR stream
 */
set_exception_handler(function($e) {
    fwrite(STDERR, $e);
    exit(1);
});


/**
 * Send information about fatal worker errors to the main process STDERR stream
 */
register_shutdown_function(function() {
    if (!$lastError = error_get_last()) {
        return;
    }
    
    $fatals = [
        E_ERROR           => 'Fatal Error',
        E_PARSE           => 'Parse Error',
        E_CORE_ERROR      => 'Core Error',
        E_CORE_WARNING    => 'Core Warning',
        E_COMPILE_ERROR   => 'Compile Error',
        E_COMPILE_WARNING => 'Compile Warning'
    ];
    
    if (!isset($fatals[$lastError['type']])) {
        return;
    }
    
    $msg = $fatals[$lastError['type']] . ': ' . $lastError['message'] . ' in ';
    $msg.= $lastError['file'] . ' on line ' . $lastError['line'];
    
    fwrite(STDERR, $msg);
});


stream_set_blocking(STDIN, FALSE);

$parser = new FrameParser(STDIN);
$writer = new FrameWriter(STDOUT);
$worker = new Worker($parser, $writer);

$reactor = (new ReactorFactory)->select();
$reactor->onReadable(STDIN, [$worker, 'onReadable']);
$reactor->run();


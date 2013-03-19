<?php

use Amp\ReactorFactory,
    Amp\Async\ProtocolException,
    Amp\Async\Processes\Io\FrameParser,
    Amp\Async\Processes\Io\FrameWriter,
    Aerys\Handlers\WorkerPool\WorkerService;

date_default_timezone_set('GMT');

define('CONTROLLER_ACCESSOR', 'aerysFrontController');

require dirname(__DIR__) . '/autoload.php';

if (empty($argv[1])) {
    throw new ProtocolException(
        'No userland front controller argument present'
    );
} elseif (!@include($argv[1])) {
    throw new ProtocolException(
        'Failed including userland front controller file: ' . $argv[1]
    );
}

if (!function_exists(CONTROLLER_ACCESSOR)) {
    throw new ProtocolException(
        'Required userland front controller accessor function not found: ' . CONTROLLER_ACCESSOR
    );
} else {
    $controller = call_user_func(CONTROLLER_ACCESSOR);
}

if (!is_callable($controller)) {
    throw new ProtocolException(
        CONTROLLER_ACCESSOR . '() MUST return a valid ASGI application callable'
    );
}

$parser = new FrameParser(STDIN);
$writer = new FrameWriter(STDOUT);
$worker = new WorkerService($parser, $writer, $controller);

$reactor = (new ReactorFactory)->select();
$reactor->onReadable(STDIN, [$worker, 'onReadable']);
$reactor->run();


<?php

use Aerys\ServerFactory,
    Aerys\Apm\ProcessManager;

date_default_timezone_set('GMT');
error_reporting(E_ALL);
set_error_handler(function($errNo, $errStr, $errFile, $errLine) {
    $msg = "$errStr in $errFile on line $errLine";
    if (error_reporting() != 0) {
        throw new ErrorException($msg, $errNo);
    }
});

require dirname(__DIR__) . '/autoload.php';

$phpPath = '/usr/local/php/5.4.11/bin/php -n -d=extension=/usr/lib64/php/modules/libevent.so';
$worker  = dirname(__DIR__) . '/apm_worker.php';
$handler = dirname(__DIR__) . '/examples/apm_example_handler.php';

$cmd = $phpPath . ' ' . $worker . ' ' . $handler;

(new ServerFactory)->createServer([[
    'listen' => '*:1337',
    'handler' => new ProcessManager($cmd)
]])->listen();



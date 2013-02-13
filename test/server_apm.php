<?php

use Aerys\ServerFactory,
    Aerys\Handlers\Apm\ProcessManager;

date_default_timezone_set('GMT');
error_reporting(E_ALL);
set_error_handler(function($errNo, $errStr, $errFile, $errLine) {
    $msg = "$errStr in $errFile on line $errLine";
    if (error_reporting() != 0) {
        throw new ErrorException($msg, $errNo);
    }
});

require dirname(__DIR__) . '/autoload.php';

$target = dirname(__DIR__) . '/apm.php';
$cmd = '/usr/local/php/5.4.11/bin/php -n -d=extension=/usr/lib64/php/modules/libevent.so ' . $target;

(new ServerFactory)->createServer([[
    'listen' => '*:1337',
    'handler' => new ProcessManager($cmd)
]])->listen();



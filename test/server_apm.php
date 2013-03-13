<?php

use Aerys\Http\Config\ServerConfigurator,
    Aerys\Http\Config\PhpProcessManagerApp;

date_default_timezone_set('GMT');

require dirname(__DIR__) . '/autoload.php';

$phpBin  = '/usr/local/php/5.4.11/bin/php -n -d=extension=/usr/lib64/php/modules/libevent.so';
$worker  = dirname(__DIR__) . '/apm_worker.php';
$handler = dirname(__DIR__) . '/examples/apm_example_handler.php';

(new ServerConfigurator)->createServer([[
    'listenOn'      => '127.0.0.1:1337',
    'name'          => 'aerys',
    'application'   => new ProcessManagerApp([
        'command'       => $phpBin . ' ' . $worker . ' ' . $handler
        'maxWorkers'    => 5,
        'workerCwd'     => NULL
    ])
]])->listen();


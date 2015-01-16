<?php

require __DIR__ . '/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

Amp\run(function($reactor) {
    $opts = getopt('', ['ipcuri:', 'config:', 'id:']);
    $bootstrapper = new Aerys\Bootstrapper($reactor);
    $server = $bootstrapper->bootServer($opts);
    $worker = new Aerys\Worker($reactor, $server, $opts['id'], $opts['ipcuri']);
    yield $worker->start();
});

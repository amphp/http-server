<?php

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

require __DIR__ . '/../vendor/autoload.php';

Amp\run(function() {
    $opts = getopt('', ['ipcuri:', 'config:', 'id:']);
    $ipcUri = $opts['ipcuri'];
    $workerId = $opts['id'];
    $configFile = $opts['config'];
    list($server, $hosts) = (new Aerys\Bootstrapper)->boot($configFile);
    $worker = new Aerys\Worker($workerId, $ipcUri, $server, $hosts);
    $worker->start();
});

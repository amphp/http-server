<?php

// <composer_hack>
// Stupid hack to autoload correctly if installed via composer and not git
// @TODO update this path to amphp/aerys once the server is moved to amphp
$dir = str_replace('\\', '/', __DIR__);
$autoloadPath = strpos($dir, 'vendor/rdlowrey/aerys/')
    ? __DIR__ . '/../../../autoload.php'
    : __DIR__ . '/../vendor/autoload.php';

require $autoloadPath;
// </composer_hack>

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

Amp\run(function() {
    $opts = getopt('', ['ipcuri:', 'config:', 'id:']);
    $ipcUri = $opts['ipcuri'];
    $workerId = $opts['id'];
    $configFile = $opts['config'];
    list($server, $hosts) = (new Aerys\Bootstrapper)->boot($configFile);
    $worker = new Aerys\Worker($workerId, $ipcUri, $server, $hosts);
    $worker->start();
});

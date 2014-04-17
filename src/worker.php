<?php

require __DIR__ . '/bootstrap.php';

$opts = getopt('', ['config:', 'ipcuri:', 'debug']);
$config = $opts['config'];
$ipcUri = $opts['ipcuri'];
$debug = isset($opts['debug']);
list($reactor, $server) = (new Aerys\Bootstrapper)->boot($config, $opts = ['debug' => $debug]);
(new Aerys\Watch\ProcWorker($reactor, $server))
    ->registerSignals()
    ->registerShutdown()
    ->start($ipcUri)
    ->run()
;

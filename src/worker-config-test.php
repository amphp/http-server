<?php

require __DIR__ . '/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);
set_error_handler(function($code, $msg, $file, $line) {
    if (error_reporting()) {
        throw new ErrorException(sprintf("%s in %s on line %d\n", $msg, $file, $line), $code);
    }
});

Amp\run(function($reactor) {
    try {
        ob_start();
        $opts = getopt('', ['config:']);
        $bootstrapper = new Aerys\Bootstrapper($reactor);
        $server = $bootstrapper->bootServer($opts);
        $data['error'] = 0;
        $data['hosts'] = $server->getBindableAddresses();
        $data['options'] = $server->getAllOptions();
    } catch (Exception $e) {
        $data['error'] = 1;
        $data['error_msg'] = $e->getMessage();
    } finally {
        $data['output'] = ob_get_clean();
        echo serialize($data);
        exit($data['error']);
    }
});

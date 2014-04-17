<?php
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
require_once __DIR__ . '/bootstrap.php';

try {
    $opts = getopt('', ['config:', 'debug', 'bind']);
    $bind = isset($opts['bind']);
    $debug = isset($opts['debug']);
    $config = $opts['config'];
    list($reactor, $server, $hosts) = (new Aerys\Bootstrapper)->boot($config, $opts = [
        'debug' => $debug,
        'bind' => $bind
    ]);
    $data['error'] = 0;
    $data['hosts'] = $hosts->getBindableAddresses();
    $data['options'] = $server->getAllOptions();
} catch (Exception $e) {
    $data['error'] = 1;
    $data['error_msg'] = $e->getMessage();
} finally {
    echo serialize($data);
    exit($data['error']);
}

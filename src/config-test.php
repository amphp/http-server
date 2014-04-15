<?php
error_reporting(E_ALL);
ini_set('display_errors', true);
require_once __DIR__ . '/bootstrap.php';

try {
    $opts = getopt('c:bd');
    $debug = isset($opts['d']);
    $config = isset($opts['c']) ? $opts['c'] : NULL;
    $shouldBind = isset($opts['b']);

    if (empty($config)) {
        throw new RuntimeException('No config file specified');
    }

    list($reactor, $server, $hosts) = (new Aerys\Bootstrapper)->boot($debug, $config);

    if ($shouldBind) {
        (new Aerys\HostBinder)->bindHosts($hosts);
    }

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

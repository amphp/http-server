<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', true);
require_once __DIR__ . '/bootstrap.php';

try {
    $opts = getopt('c:b');
    $shouldBind = isset($opts['b']);
    $configFile = isset($opts['c']) ? $opts['c'] : NULL;

    if (empty($configFile)) {
        throw new RuntimeException('No config file specified');
    }

    $binOptions = (new Aerys\Start\BinOptions)->loadOptions(['config' => $configFile]);
    list($reactor, $server, $hosts) = (new Aerys\Start\Bootstrapper)->boot($binOptions);
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

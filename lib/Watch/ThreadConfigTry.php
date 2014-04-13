<?php

namespace Aerys\Watch;

use Aerys\Start\BinOptions,
    Aerys\Start\Bootstrapper;

class ThreadConfigTry extends \Thread {
    public $error;
    public $bindTo;
    public $options;
    private $configFile;

    public function __construct($configFile) {
        $this->configFile = $configFile;
    }

    public function run() {
        register_shutdown_function([$this, 'shutdown']);
        require __DIR__ . '/../../src/bootstrap.php';
        $binOptions = (new BinOptions)->loadOptions(['config' => $this->configFile]);
        list($reactor, $server, $hosts) = (new Bootstrapper)->boot($binOptions);
        $this->bindTo = $hosts->getBindableAddresses();
        $this->options = $server->getAllOptions();
    }

    public function shutdown() {
        $fatals = [E_ERROR, E_PARSE, E_USER_ERROR, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING];
        $error = error_get_last();
        if ($error && in_array($error['type'], $fatals)) {
            extract($error);
            $this->error = sprintf("%s in %s on line %d", $message, $file, $line);
        }
    }
}

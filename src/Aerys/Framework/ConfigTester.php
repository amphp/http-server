<?php

namespace Aerys\Framework;

use Aerys\HostBinder;

class ConfigTester {

    private $bootstrapper;
    private $binOptions;
    private $shortOpts = 'c:b';
    private $exitCode = 0;

    function __construct(Bootstrapper $b = NULL, BinOptions $o = NULL, HostBinder $hb = NULL) {
        $this->bootstrapper = $b ?: new Bootstrapper;
        $this->binOptions = $o ?: new BinOptions;
        $this->hostBinder = $hb ?: new HostBinder;
    }

    function configure() {
        list($configFile, $shouldBind) = $this->getCliOptions();
        if (!$configFile) {
            $this->exitCode = 1;
            return $this->encodeDataForTransport([
                'error' => 1,
                'error_msg' => sprintf('JSON encode failure: %s', json_last_error_msg())
            ]);
        }

        ob_start();
        $data = $this->testConfig($configFile, $shouldBind);
        $outputBuffer = ob_get_contents();
        ob_end_clean();

        if (strlen($outputBuffer)) {
            $data['output'] = $outputBuffer;
        }

        return $this->encodeDataForTransport($data);
    }

    private function getCliOptions() {
        $opts = getopt($this->shortOpts);
        $configFile = isset($opts['c']) ? $opts['c'] : NULL;
        $shouldBind = isset($opts['b']);

        return [$configFile, $shouldBind];
    }

    private function testConfig($configFile, $shouldBind) {
        try {
            $data = [
                'error' => 0,
                'file' => $configFile
            ];

            $this->binOptions->loadOptions(['config' => $configFile]);

            list($reactor, $server, $hosts) = $this->bootstrapper->boot($this->binOptions);

            if ($shouldBind) {
                $this->hostBinder->bindHosts($hosts);
            }

            $data['hosts'] = $hosts->getBindableAddresses();
            $data['options'] = $server->getAllOptions();
            $this->exitCode = 0;

        } catch (Exception $e) {
            $data['error'] = 1;
            $data['error_msg'] = $e->getMessage();
            $this->exitCode = 1;
        }

        return $data;
    }

    private function encodeDataForTransport(array $data) {
        $json = json_encode($data);
        if ($json) {
            $this->exitCode = 0;
        } else {
            $json = json_encode([
                'error' => 1,
                'error_msg' => sprintf('JSON encode failure: %s', json_last_error_msg())
            ]);
            $this->exitCode = 1;
        }

        return $json;
    }

    function getExitCode() {
        return $this->exitCode;
    }
}

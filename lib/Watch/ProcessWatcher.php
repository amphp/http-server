<?php

namespace Aerys\Watch;

use Alert\Reactor,
    Alert\ReactorFactory,
    Aerys\Start\BinOptions;

class ProcessWatcher implements ServerWatcher {

    private $reactor;
    private $cpuCounter;
    private $binOptions;
    private $workers = [];

    function __construct(Reactor $reactor = NULL, CpuCounter $cpuCounter = NULL) {
        $this->reactor = $reactor ?: (new ReactorFactory)->select();
        $this->cpuCounter = $cpuCounter ?: new CpuCounter;
    }

    /**
     * Monitor workers and respawn in the event of a fatal error
     *
     * @param \Aerys\Framework\BinOptions
     * @return void
     */
    function watch(BinOptions $binOptions) {
        $this->binOptions = $binOptions;
        $this->validateConfigPath();
        $this->forkWorkers();
        $this->reactor->run();
    }

    private function validateConfigPath() {
        $configPath = escapeshellarg($this->binOptions->getConfig());
        $cmd = $this->generateConfigValidationCommand($configPath);
        exec($cmd, $output, $exitCode);

        $output = implode($output, "\n");
        $json = json_decode($output, TRUE);

        if ($exitCode) {
            throw new StartException(
                $json['error_msg']
            );
        } else {
            $addresses = array_unique($json['hosts']);
                foreach ($addresses as $address) {
                $address = substr(str_replace('0.0.0.0', '*', $address), 6);
                printf("Listening for HTTP traffic on %s ...\n", $address);
            }
        }
    }

    private function generateConfigValidationCommand($appConfigPath) {
        $parts = [];
        $parts[] = PHP_BINARY;
        if ($ini = get_cfg_var('cfg_file_path')) {
            $parts[] = "-c $ini";
        }
        $parts[] = __DIR__ . "/../../src/config-test.php -c";
        $parts[] = $appConfigPath;
        $parts[] = ' -b';

        return implode(' ', $parts);
    }

    private function forkWorkers() {
        $workerCount = $this->binOptions->getWorkers() ?: $this->cpuCounter->count();
        for ($i=0; $i < $workerCount; $i++) {
            $this->fork();
        }
    }

    private function fork() {
        $exe = __DIR__ . "/../../src/worker.php -c";
        $ini = ($ini = get_cfg_var('cfg_file_path')) ? " -c \"$ini\"" : '';
        $cmd = sprintf("%s%s %s %s", PHP_BINARY, $ini, $exe, $this->binOptions);

        $descriptors = [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w']
        ];
        $process = proc_open($cmd, $descriptors, $pipes);

        if (is_resource($process)) {
            list($stdin, $stdout, $stderr) = $pipes;
            stream_set_blocking($stdout, FALSE);
            $watcherId = $this->reactor->onReadable($stdout, function($watcherId, $stream) {
                $this->onReadableChild($watcherId, $stream);
            });
            $this->workers[$watcherId] = [$process, $pipes];
        } else {
            throw new \RuntimeException(
                'Failed opening worker process pipe'
            );
        }
    }

    private function onReadableChild($watcherId, $stdout) {
        while (($data = fread($stdout, 8192)) || $data === '0') {
            echo $data;
        }
        if (!is_resource($stdout) || feof($stdout)) {
            $this->fork();
            $this->reactor->cancel($watcherId);
            list($process, $pipes) = $this->workers[$watcherId];
            unset($this->workers[$watcherId]);
            foreach ($pipes as $pipe) {
                @fclose($pipe);
            }
            @proc_terminate($process);
        }
    }

}

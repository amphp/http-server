<?php

namespace Aerys\Framework;

use Aerys\Server;

class ForkWatcher implements ServerWatcher {

    private $server;
    private $workers;

    function __construct(Server $server, BinOptions $options) {
        $this->server = $server;
        $this->workers = $options->getWorkers() ?: $this->countCpuCores();
    }
    
    private function countCpuCores() {
        $cmd = "uname";
        $os = strtolower(trim(shell_exec($cmd)));
        
        switch ($os) {
           case "linux":
              $cmd = "cat /proc/cpuinfo | grep processor | wc -l";
              $cores = $this->executeCpuCoreCountShellCommand($cmd);
              break;
           case "freebsd":
              $cmd = "sysctl -a | grep 'hw.ncpu' | cut -d ':' -f2";
              $cores = $this->executeCpuCoreCountShellCommand($cmd);
              break;
           default:
              $cores = 1;
        }
        
        return $cores;
    }
    
    private function executeCpuCoreCountShellCommand($cmd) {
        $execResult = shell_exec($cmd);
        $cores = intval(trim($execResult));
        
        return $cores;
    }

    /**
     * Monitor forked workers and respawn dead children as needed
     *
     * @return void
     */
    function watch() {
        foreach ($this->server->bind() as $address) {
            $address = substr(str_replace('0.0.0.0', '*', $address), 6);
            fwrite(STDOUT, sprintf("Listening for HTTP traffic on %s ...\n", $address));
        }

        for ($i = 0; $i < $this->workers; $i++) {
            $this->fork();
        }

        while (TRUE) {
            pcntl_wait($status);
            $this->fork();
        }
    }

    private function fork() {
        $this->server->beforeFork();
        $pid = pcntl_fork();

        if ($pid === 0) {
            $this->server->afterFork();
            $this->server->listen();
            $this->server->run();
        } elseif ($pid === -1) {
            throw new \RuntimeException(
                'Server process fork failed'
            );
        }
    }

}

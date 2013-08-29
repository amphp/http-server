<?php

namespace Aerys\Framework;

use Aerys\Server;

class ForkWatcher implements ServerWatcher {

    private $server;
    private $workers = 2;

    function __construct(Server $server, BinOptions $options) {
        $this->server = $server;
        if ($workers = $options->getWorkers()) {
            $this->workers = $workers;
        }
    }

    /**
     * Monitor forked workers respawning dead children as needed
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

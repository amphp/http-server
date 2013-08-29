<?php

namespace Aerys\Framework;

use Alert\ReactorFactory,
    Aerys\Server;

class ServerWatcherFactory {

    private $reactorFactory;
    private $isExtPcntlEnabled;
    private $processWorker = 'proc_worker';

    function __construct(ReactorFactory $reactorFactory = NULL) {
        $this->reactorFactory = $reactorFactory ?: new ReactorFactory;
        $this->isExtPcntlEnabled = extension_loaded('pcntl');
    }

    function makeWatcher(BinOptions $binOptions, $argv) {
        return $this->isExtPcntlEnabled
            ? $this->makeForkWatcher($binOptions)
            : $this->makeProcessWatcher($binOptions, $argv);

    }

    private function makeForkWatcher(BinOptions $options) {
        $reactor = $this->reactorFactory->select();
        $server = new Server($reactor);
        $bootstrapper = new Bootstrapper;
        $server = $bootstrapper->boot($reactor, $server, $options);

        return new ForkWatcher($server, $options);
    }

    private function makeProcessWatcher(BinOptions $binOptions, $argv) {
        $reactor = $this->reactorFactory->select();
        $argv[0] = sprintf("%s/%s", dirname($argv[0]), $this->processWorker);

        $cmd = implode(' ', $argv);

        return new ProcessWatcher($reactor, $cmd);
    }

}

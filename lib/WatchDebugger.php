<?php

declare(ticks = 1);

namespace Aerys;

class WatchDebugger implements Watcher {
    public function watch(BinOptions $binOptions) {
        $config = $binOptions->getConfig();
        list($reactor, $server, $hosts) = (new Bootstrapper)->boot($config, $opt = [
            'bind' => TRUE,
            'debug' => $binOptions->getDebug(),
        ]);

        register_shutdown_function(function() use ($server) {
            $this->shutdown($server);
        });

        // phpdbg captures SIGINT so don't intercept if running inside the debugger
        if ((substr(PHP_BINARY, -6) !== 'phpdbg') && extension_loaded('pcntl')) {
            pcntl_signal(SIGINT, function() use ($server) {
                $server->stop()->onResolution(function(){ exit; });
            });
        }

        foreach ($hosts->getBindableAddresses() as $addr) {
            $addr = substr(str_replace('0.0.0.0', '*', $addr), 6);
            printf("Listening for HTTP traffic on %s ...\n", $addr);
        }

        $reactor->run();
    }

    private function shutdown(Server $server) {
        if (!$err = error_get_last()) {
            return;
        }

        switch ($err['type']) {
            case E_ERROR:
            case E_PARSE:
            case E_USER_ERROR:
            case E_CORE_ERROR:
            case E_CORE_WARNING:
            case E_COMPILE_ERROR:
            case E_COMPILE_WARNING:
                extract($err);
                printf("%s in %s on line %d\n", $message, $file, $line);
                $server->stop()->onResolution(function() { exit; });
                break;
        }
    }
}

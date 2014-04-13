<?php

namespace Aerys\Watch;

use Aerys\Server,
    Aerys\BinOptions,
    Aerys\Bootstrapper;

class DebugWatcher {
    public function watch(BinOptions $binOptions) {
        list($reactor, $server, $hosts) = (new Bootstrapper)->boot($binOptions);

        register_shutdown_function(function() use ($server) {
            $this->shutdown($server);
        });

        $server->start($hosts);

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
                $server->stop()->onComplete(function() { exit; });
                break;
        }
    }
}

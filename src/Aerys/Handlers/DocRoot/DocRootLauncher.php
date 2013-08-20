<?php

namespace Aerys\Handlers\DocRoot;

use Auryn\Injector,
    Aerys\Config\ConfigLauncher,
    Aerys\Config\ConfigException;

class DocRootLauncher extends ConfigLauncher {

    private $handlerClass = 'Aerys\Handlers\DocRoot\DocRootHandler';

    function launch(Injector $injector) {
        $handler = $injector->make($this->handlerClass);
        $this->configureHandler($handler);

        return $handler;
    }

    private function configureHandler($handler) {
        try {
            $handler->setAllOptions($this->getConfig());
        } catch (\Exception $e) {
            throw new ConfigException(
                $msg = 'DocRoot configuration failed',
                $errCode = 0,
                $previous = $e
            );
        }
    }

}

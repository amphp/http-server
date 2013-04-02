<?php

namespace Aerys\Config;

use Auryn\Injector;

class WorkerPoolApp implements AppLauncher {
    
    private $options = [
        'workerCmd'         => NULL,
        'poolSize'          => NULL,
        'responseTimeout'   => NULL,
        'workerCwd'         => NULL,
        'workerProcedure'   => 'main'
    ];
    
    function __construct(array $options) {
        $this->setOptions($options);
        $this->validateOptions();
    }
    
    private function setOptions(array $options) {
        foreach ($options as $key => $value) {
            if (isset($value) && array_key_exists($key, $this->options)) {
                $this->options[$key] = $value;
            }
        }
    }
    
    private function validateOptions() {
        if (empty($this->options['workerCmd'])) {
            throw new ConfigException(
                "Process pool `workerCmd` directive required to spawn worker processes"
            );
        }
        
        if (isset($this->options['workerCwd']) && !is_dir($this->options['workerCwd'])) {
            throw new ConfigException(
                "Process manager `workerCwd` directive must specify a valid directory path"
            );
        }
    }
    
    function launchApp(Injector $injector) {
        $dispatcher = $injector->make("Amp\\Async\\Dispatcher");
        
        $dispatcher->setCallTimeout($this->options['responseTimeout']);
        $dispatcher->notifyOnPartialResult(TRUE);
        
        $dispatcher->start(
            $this->options['poolSize'],
            $this->options['workerCmd'],
            $this->options['workerCwd']
        );
        
        $handler = $injector->make("Aerys\\Handlers\\WorkerPool\\Handler", [
            ':dispatcher' => $dispatcher
        ]);
        
        $handler->setWorkerProcedure($this->options['workerProcedure']);
        
        return $handler;
    }
    
}


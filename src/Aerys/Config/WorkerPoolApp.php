<?php

namespace Aerys\Config;

use Auryn\Injector;

class WorkerPoolApp implements AppLauncher {
    
    private $options = [
        'workerCmd'         => NULL,
        'maxWorkers'        => NULL,
        'responseTimeout'   => NULL,
        'workerCwd'         => NULL
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
        $opts = $this->options;
        $workerCmd = $opts['workerCmd'];
        
        unset($opts['workerCmd']);
        
        $processDispatcher = $injector->make("Amp\\Async\\Processes\\ProcessDispatcher", [
            ':workerCmd' => $workerCmd
        ]);
        
        foreach ($opts as $key => $value) {
            if (isset($value)) {
                $setter = "set" . ucfirst($key);
                $processDispatcher->$setter($value);
            }
        }
        
        $processDispatcher->start();
        
        return $injector->make("Aerys\\Handlers\\WorkerPool\\Handler", [
            ':dispatcher' => $processDispatcher
        ]);
    }
    
}


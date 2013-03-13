<?php

namespace Aerys\Http\Config;

use Auryn\Injector,
    Aerys\Apm\ProcessManager;

class ProcessManagerApp implements AppLauncher {
    
    private $options = [
        'command'       => NULL,
        'maxWorkers'    => NULL,
        'workerCwd'     => NULL
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
        if (empty($this->options['command'])) {
            throw new ConfigException(
                "Process manager `command` directive required to spawn worker processes"
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
        $cmd = $opts['command'];
        
        unset($opts['command']);
        
        $app = $injector->make("Aerys\\Apm\\ProcessManager", [
            ':command' => $cmd
        ]);
        
        foreach ($opts as $key => $value) {
            if (isset($value)) {
                $setter = "set" . ucfirst($key);
                $app->$setter($value);
            }
        }
        
        $app->init();
        
        return $app;
    }
    
}


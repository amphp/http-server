<?php

namespace Aerys\Http\Config;

use Auryn\Injector;

class StaticFilesApp implements AppLauncher {
    
    private $handlerClass = "Aerys\\Http\\Filesys";
    
    private $options = [
        'docRoot'                   => NULL,
        'indexes'                   => NULL,
        'eTagMode'                  => NULL,
        'expiresHeaderPeriod'       => NULL,
        'customMimeTypes'           => NULL,
        'defaultTextCharset'        => NULL,
        'fileDescriptorCacheTtl'    => NULL
    ];
    
    function __construct(array $options) {
        $this->setOptions($options);
        $this->validateDocRoot();
    }
    
    private function setOptions(array $options) {
        foreach ($options as $key => $value) {
            if (isset($value) && array_key_exists($key, $this->options)) {
                $this->options[$key] = $value;
            }
        }
    }
    
    private function validateDocRoot() {
        $docRoot = $this->options['docRoot'];
        
        if (!($docRoot && is_dir($docRoot) && is_readable($docRoot))) {
            throw new ConfigException(
                __CLASS__ . "::__construct requires a 'docRoot' key specifying a readable " .
                "directory from which to server static files"
            );
        }
    }
    
    function launchApp(Injector $injector) {
        $opts = $this->options;
        $docRoot = $opts['docRoot'];
        unset($opts['docRoot']);
        
        return $injector->make($this->handlerClass, [
            ':docRoot' => $docRoot,
            ':options' => $opts
        ]);
    }
    
}


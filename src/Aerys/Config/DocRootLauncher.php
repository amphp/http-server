<?php

namespace Aerys\Config;

use Auryn\Injector;

class DocRootLauncher implements Launcher {
    
    private $handlerClass = 'Aerys\Handlers\DocRoot\DocRootHandler';
    private $options = [
        'docRoot'                   => NULL,
        'indexes'                   => NULL,
        'eTagMode'                  => NULL,
        'expiresHeaderPeriod'       => NULL,
        'defaultMimeType'           => NULL,
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
                "directory from which to serve static files"
            );
        }
    }
    
    function launchApp(Injector $injector) {
        $opts = $this->options;
        $handler = $injector->make($this->handlerClass, [
            ':docRoot' => $opts['docRoot']
        ]);
        
        unset($opts['docRoot']);
        
        foreach ($opts as $key => $value) {
            $setter = "set" . ucfirst($key);
            if (method_exists($handler, $setter) && isset($value)) {
                $handler->$setter($value);
            }
        }
        
        return $handler;
    }
    
}


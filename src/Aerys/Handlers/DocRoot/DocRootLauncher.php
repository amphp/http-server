<?php

namespace Aerys\Handlers\DocRoot;

use Auryn\Injector,
    Aerys\Config\ConfigLauncher,
    Aerys\Config\ConfigException;

class DocRootLauncher extends ConfigLauncher {
    
    private $handlerClass = 'Aerys\Handlers\DocRoot\DocRootHandler';
    private $configDefaults = [
        'docRoot'                   => NULL,
        'indexes'                   => NULL,
        'eTagMode'                  => NULL,
        'expiresHeaderPeriod'       => NULL,
        'defaultMimeType'           => NULL,
        'customMimeTypes'           => NULL,
        'defaultTextCharset'        => NULL,
        'fileDescriptorCacheTtl'    => NULL
    ];
    
    function launch(Injector $injector) {
        $config = array_filter($this->getConfig(), function($x) { return $x !== NULL;}) ?: [];
        $config = array_intersect_key($config, $this->configDefaults);
        $config = array_merge($this->configDefaults, $config);
        
        if (!(isset($config['docRoot']) && is_string($config['docRoot']))) {
            throw new ConfigException(
                'docRoot configuration directive required to launch DocRoot'
            );
        } else {
            $docRoot = $config['docRoot'];
            unset($config['docRoot']);
        }
        
        if (!(is_dir($docRoot) && is_readable($docRoot))) {
            throw new ConfigException(
                'docRoot configuration directive must specify a readable directory path'
            );
        }
        
        $handler = $injector->make($this->handlerClass, [
            ':docRoot' => $docRoot
        ]);
        
        foreach ($config as $key => $value) {
            $setter = "set" . ucfirst($key);
            if (method_exists($handler, $setter) && isset($value)) {
                $handler->$setter($value);
            }
        }
        
        return $handler;
    }
    
}


<?php

namespace Aerys;

class ServerFactory {
    
    function createServer(array $config) {
        $options = isset($config['aerys.globals']) ? $config['aerys.globals'] : [];
        $tlsConf = isset($options['tlsDefinitions']) ? $options['tlsDefinitions'] : [];
        
        unset(
            $config['aerys.globals'],
            $options['tlsDefinitions']
        );
        
        if (empty($config)) {
            throw new \Exception;
        } else {
            $hostConf = $config;
        }
        
        $hostDefs = $this->generateHostDefs($hostConf);
        $tlsDefs = $this->generateTlsDefs($tlsConf);
        $eventBase = $this->selectEventBase();
        
        $server = new Server($eventBase, $hostDefs, $tlsDefs);
        
        foreach ($options as $key => $value) {
            $server->setOption($key, $value);
        }
        
        return $server;
    }
    
    /**
     * @todo select best available event base according to system availability
     */
    private function selectEventBase() {
        return new Engine\LibEventBase;
    }
    
    /**
     * @todo determine appropriate exception to throw on config errors
     */
    private function generateHostDefs(array $hostConf) {
        $hostDefs = new HostCollection;
        
        foreach ($hostConf as $hostArr) {
            if (!empty($hostArr['listen'])) {
                list($interface, $port) = explode(':', $hostArr['listen']);
            } else {
                throw new \Exception;
            }
            
            if (!empty($hostArr['handler'])) {
                $handler = $hostArr['handler'];
            } else {
                throw new \Exception;
            }
            
            $name = empty($hostArr['name']) ? '127.0.0.1' : $hostArr['name'];
            
            unset(
                $hostArr['listen'],
                $hostArr['handler'],
                $hostArr['name']
            );
            
            $mods = $hostArr;
            
            $host = new Host($handler, $name, $port, $interface, $mods);
            $hostDefs->attach($host);
        }
        
        return $hostDefs;
    }
    
    /**
     * @todo determine appropriate exception to throw on config errors
     */
    private function generateTlsDefs(array $tlsConf) {
        $tlsDefs = [];
        
        foreach ($tlsConf as $address => $tlsDef) {
            if (!(isset($tlsDef['localCertFile'])
                && isset($tlsDef['certPassphrase'])
                && isset($tlsDef['allowSelfSigned'])
                && isset($tlsDef['verifyPeer'])
            )) {
                throw new \Exception;
            }
            
            $tlsDefs[] = new TlsDefinition(
                $address,
                $tlsDef['localCertFile'],
                $tlsDef['certPassphrase'],
                $tlsDef['allowSelfSigned'],
                $tlsDef['verifyPeer']
            );
        }
        
        return $tlsDefs;
    }
    
}


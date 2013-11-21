<?php

namespace Aerys\Framework;

use Aerys\Host;

class HostBuilder {
    
    private $responderBuilder;
    
    function __construct(ResponderBuilder $rb) {
        $this->responderBuilder = $rb;
    }
    
    /**
     * Create a server Host from an App definition
     *
     * @param \Aerys\Framework\App An App definition
     * @throws \Aerys\Framework\ConfigException On invalid definition or responder build failure
     * @return \Aerys\Host Returns the generated host instance
     */
    function buildHost(App $app) {
        $definition = $app->toArray();
        $responder = $this->responderBuilder->buildResponder($definition);
        
        $host = $this->doHostGeneration(
            $definition['port'],
            $definition['address'],
            $definition['name'],
            $responder
        );

        if ($tlsDefinition = $definition['encryption']) {
            $this->setHostEncryption($host, $tlsDefinition);
        }

        return $host;
    }

    private function doHostGeneration($port, $ip, $name, callable $responder) {
        try {
            $name = $name ?: $ip;
            return new Host($ip, $port, $name, $responder);
        } catch (\Exception $lastException) {
            throw new ConfigException(
                sprintf('Host build failure: %s', $lastException->getMessage()),
                $errorCode = 0,
                $lastException
            );
        }
    }

    private function setHostEncryption(Host $host, array $tlsDefinition) {
        try {
            $host->setEncryptionContext($tlsDefinition);
        } catch (\InvalidArgumentException $lastException) {
            throw new ConfigException(
                sprintf('Host build failure: %s', $lastException->getMessage()),
                $errorCode = 0,
                $lastException
            );
        }
    }
    
}

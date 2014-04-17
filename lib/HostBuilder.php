<?php

namespace Aerys;

class HostBuilder {
    private $responderBuilder;

    public function __construct(ResponderBuilder $rb) {
        $this->responderBuilder = $rb;
    }

    /**
     * Create a server Host from an App definition
     *
     * @param \Aerys\Framework\App An App definition
     * @throws \Aerys\Framework\BootException On invalid definition or responder build failure
     * @return \Aerys\Host Returns the generated host instance
     */
    public function buildHost(App $app) {
        $appDefinition = $app->toArray();
        $responder = $this->responderBuilder->buildResponder($appDefinition);

        $host = $this->doHostGeneration(
            $appDefinition[App::PORT],
            $appDefinition[App::ADDRESS],
            $appDefinition[App::NAME],
            $responder
        );

        if ($tlsDefinition = $appDefinition[App::ENCRYPTION]) {
            $this->setHostEncryption($host, $tlsDefinition);
        }

        return $host;
    }

    private function doHostGeneration($port, $ip, $name, callable $responder) {
        try {
            $name = $name ?: $ip;
            return new Host($ip, $port, $name, $responder);
        } catch (\Exception $e) {
            throw new BootException(
                sprintf('Host build failure: %s', $e->getMessage()),
                $code = 0,
                $e
            );
        }
    }

    private function setHostEncryption(Host $host, array $tlsDefinition) {
        try {
            $host->setEncryptionContext($tlsDefinition);
        } catch (\InvalidArgumentException $lastException) {
            throw new BootException(
                sprintf('Host build failure: %s', $lastException->getMessage()),
                $errorCode = 0,
                $lastException
            );
        }
    }
}

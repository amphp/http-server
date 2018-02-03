<?php

namespace Aerys;

interface HttpDriverFactory {
    /**
     * Selects an HTTP driver based on the given client.
     *
     * @param \Aerys\Client $client
     *
     * @return \Aerys\HttpDriver
     */
    public function selectDriver(Client $client): HttpDriver;

    /**
     * @return string[] A list of supported application-layer protocols (ALPNs).
     */
    public function getApplicationLayerProtocols(): array;
}

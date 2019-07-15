<?php

namespace Amp\Http\Server\Driver;

interface HttpDriverFactory
{
    /**
     * Selects an HTTP driver based on the given client.
     *
     * @param Client $client
     *
     * @return HttpDriver
     */
    public function selectDriver(Client $client): HttpDriver;

    /**
     * @return string[] A list of supported application-layer protocols (ALPNs).
     */
    public function getApplicationLayerProtocols(): array;
}

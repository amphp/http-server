<?php

namespace Aerys;

class VhostContainer implements \Countable, Monitor {
    private $vhosts = [];
    private $cachedVhostCount = 0;
    private $httpDrivers = [];
    private $defaultHttpDriver;
    private $setupHttpDrivers = [];
    private $setupArgs;

    public function __construct(HttpDriver $driver) {
        $this->defaultHttpDriver = $driver;
    }

    /**
     * Add a virtual host to the collection.
     *
     * @param \Aerys\Vhost $vhost
     * @return void
     */
    public function use(Vhost $vhost) {
        $vhost = clone $vhost; // do not allow change of state after use()
        $this->preventCryptoSocketConflict($vhost);
        foreach ($vhost->getIds() as $id) {
            if (isset($this->vhosts[$id])) {
                list($host, $port, $interfaceAddr, $interfacePort) = explode(":", $id);
                throw new \LogicException(
                    $host === "*"
                        ? "Cannot have two default hosts " . ($interfacePort == $port ? "" : "on port $port ") . "on the same interface ($interfaceAddr:$interfacePort)"
                        : "Cannot have two hosts with the same name ($host" . ($interfacePort == $port ? "" : ":$port") . ") on the same interface ($interfaceAddr:$interfacePort)"
                );
            }

            $this->vhosts[$id] = $vhost;
        }
        $this->addHttpDriver($vhost);
        $this->cachedVhostCount++;
    }

    // TLS is inherently bound to a specific interface. Unencrypted wildcard hosts will not work on encrypted interfaces and vice versa.
    private function preventCryptoSocketConflict(Vhost $new) {
        foreach ($this->vhosts as $old) {
            // If both hosts are encrypted or both unencrypted there is no conflict
            if ($new->isEncrypted() == $old->isEncrypted()) {
                continue;
            }
            foreach ($old->getInterfaces() as list($address, $port)) {
                if (in_array($port, $new->getPorts($address))) {
                    throw new \Error(
                        sprintf(
                            "Cannot register encrypted host `%s`; unencrypted " .
                            "host `%s` registered on conflicting interface `%s`",
                            $new->IsEncrypted() ? $new->getName() : $old->getName(),
                            $new->IsEncrypted() ? $old->getName() : $new->getName(),
                            "$address:$port"
                        )
                    );
                }
            }
        }
    }

    private function addHttpDriver(Vhost $vhost) {
        $driver = $vhost->getHttpDriver() ?? $this->defaultHttpDriver;
        foreach ($vhost->getInterfaces() as list($address, $port)) {
            $defaultDriver = $this->httpDrivers[$port][\strlen(inet_pton($address)) === 4 ? "0.0.0.0" : "::"] ?? $driver;
            if (($this->httpDrivers[$port][$address] ?? $defaultDriver) !== $driver) {
                throw new \Error(
                    "Cannot use two different HttpDriver instances on an equivalent address-port pair"
                );
            }
            if ($address == "0.0.0.0" || $address == "::") {
                foreach ($this->httpDrivers[$port] ?? [] as $oldAddr => $oldDriver) {
                    if ($oldDriver !== $driver && (\strlen(inet_pton($address)) === 4) == ($address == "0.0.0.0")) {
                        throw new \Error(
                            "Cannot use two different HttpDriver instances on an equivalent address-port pair"
                        );
                    }
                }
            }
            $this->httpDrivers[$port][$address] = $driver;
        }
        $hash = spl_object_hash($driver);
        if ($this->setupArgs && $this->setupHttpDrivers[$hash] ?? false) {
            $driver->setup(...$this->setupArgs);
            $this->setupHttpDrivers[$hash] = true;
        }
    }

    public function setupHttpDrivers(...$args) {
        if ($this->setupHttpDrivers) {
            throw new \Error("Can setup http drivers only once");
        }
        $this->setupArgs = $args;
        foreach ($this->httpDrivers as $drivers) {
            foreach ($drivers as $driver) {
                $hash = spl_object_hash($driver);
                if ($this->setupHttpDrivers[$hash] ?? false) {
                    continue;
                }
                $this->setupHttpDrivers[$hash] = true;
                $driver->setup(...$args);
            }
        }
    }

    /**
     * Select the suited HttpDriver instance, filtered by address and port pair.
     */
    public function selectHttpDriver($address, $port) {
        return $this->httpDrivers[$port][$address] ??
            $this->httpDrivers[$port][\strpos($address, ":") === false ? "0.0.0.0" : "::"];
    }

    /**
     * Select a virtual host match for the specified request according to RFC 7230 criteria.
     *
     * Note: For HTTP/1.0 requests (aka omitting a Host header), a proper Vhost will only ever be returned
     *       if there is a matching wildcard host.
     *
     * @param \Aerys\InternalRequest $ireq
     * @return Vhost|null Returns a Vhost object and boolean TRUE if a valid host selected, FALSE otherwise
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec5.html#sec5.2
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec19.html#sec19.6.1.1
     */
    public function selectHost(InternalRequest $ireq) {
        $client = $ireq->client;
        $serverId = ":{$client->serverAddr}:{$client->serverPort}";

        $explicitHostId = "{$ireq->uriHost}:{$ireq->uriPort}{$serverId}";
        if (isset($this->vhosts[$explicitHostId])) {
            return $this->vhosts[$explicitHostId];
        }

        $addressWildcardHost = "*:{$ireq->uriPort}{$serverId}";
        if (isset($this->vhosts[$addressWildcardHost])) {
            return $this->vhosts[$addressWildcardHost];
        }

        $portWildcardHostId = "{$ireq->uriHost}:0{$serverId}";
        if (isset($this->vhosts[$portWildcardHostId])) {
            return $this->vhosts[$portWildcardHostId];
        }

        $addressPortWildcardHost = "*:0{$serverId}";
        if (isset($this->vhosts[$addressPortWildcardHost])) {
            return $this->vhosts[$addressPortWildcardHost];
        }

        $wildcardIP = \strpos($client->serverAddr, ":") === false ? "0.0.0.0" : "[::]";
        $serverId = ":$wildcardIP:{$client->serverPort}";

        $explicitHostId = "{$ireq->uriHost}:{$ireq->uriPort}{$serverId}";
        if (isset($this->vhosts[$explicitHostId])) {
            return $this->vhosts[$explicitHostId];
        }

        $addressWildcardHost = "*:{$ireq->uriPort}{$serverId}";
        if (isset($this->vhosts[$addressWildcardHost])) {
            return $this->vhosts[$addressWildcardHost];
        }

        $portWildcardHostId = "{$ireq->uriHost}:0{$serverId}";
        if (isset($this->vhosts[$portWildcardHostId])) {
            return $this->vhosts[$portWildcardHostId];
        }

        $addressPortWildcardHost = "*:0{$serverId}";
        if (isset($this->vhosts[$addressPortWildcardHost])) {
            return $this->vhosts[$addressPortWildcardHost];
        }

        return null; // nothing found
    }

    /**
     * Retrieve an array of unique socket addresses on which hosts should listen.
     *
     * @return array Returns an array of unique host addresses in the form: tcp://ip:port
     */
    public function getBindableAddresses(): array {
        return array_unique(array_merge(...array_values(array_map(function ($vhost) {
            return $vhost->getBindableAddresses();
        }, $this->vhosts))));
    }

    /**
     * Retrieve stream encryption settings by bind address.
     *
     * @return array
     */
    public function getTlsBindingsByAddress(): array {
        $bindMap = [];
        $sniNameMap = [];
        foreach ($this->vhosts as $vhost) {
            if (!$vhost->isEncrypted()) {
                continue;
            }

            foreach ($vhost->getBindableAddresses() as $bindAddress) {
                $contextArr = $vhost->getTlsContextArr();
                $bindMap[$bindAddress] = $contextArr;

                if ($vhost->hasName()) {
                    $sniNameMap[$bindAddress][$vhost->getName()] = $contextArr["local_cert"];
                }
            }
        }

        // If we have multiple different TLS certs on the same bind address we need to assign
        // the "SNI_server_name" key to enable the SNI extension.
        foreach (array_keys($bindMap) as $bindAddress) {
            if (isset($sniNameMap[$bindAddress]) && count($sniNameMap[$bindAddress]) > 1) {
                $bindMap[$bindAddress]["SNI_server_name"] = $sniNameMap[$bindAddress];
            }
        }

        return $bindMap;
    }

    public function count() {
        return $this->cachedVhostCount;
    }

    public function __debugInfo() {
        return [
            "vhosts" => $this->vhosts,
        ];
    }

    public function monitor(): array {
        return array_map(function ($vhost) { return $vhost->monitor(); }, $this->vhosts);
    }
}

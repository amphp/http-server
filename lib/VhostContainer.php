<?php

namespace Aerys;

class VhostContainer implements \Countable, Monitor {
    private $vhosts = [];
    private $cachedVhostCount = 0;
    private $defaultHost;
    private $httpDrivers = [];
    private $defaultHttpDriver;
    private $setupHttpDrivers = [];
    private $setupArgs;

    public function __construct(HttpDriver $driver) {
        $this->defaultHttpDriver = $driver;
    }

    /**
     * Add a virtual host to the collection
     *
     * @param \Aerys\Vhost $vhost
     * @return void
     */
    public function use(Vhost $vhost) {
        $vhost = clone $vhost; // do not allow change of state after use()
        $this->preventCryptoSocketConflict($vhost);
        foreach ($vhost->getIds() as $id) {
            if (isset($this->vhosts[$id])) {
                throw new \LogicException(
                    $vhost->getName() == ""
                        ? "Cannot have two default hosts on the same `$id` interface"
                        : "Cannot have two hosts with the same `$id` name"
                );
            }

            $this->vhosts[$id] = $vhost;
        }
        $this->addHttpDriver($vhost);
        $this->cachedVhostCount++;
    }

    private function preventCryptoSocketConflict(Vhost $new) {
        foreach ($this->vhosts as $old) {
            // If both hosts are encrypted or both unencrypted there is no conflict
            if ($new->isEncrypted() == $old->isEncrypted()) {
                continue;
            }
            foreach ($old->getInterfaces() as list($address, $port)) {
                if (in_array($port, $new->getPorts($address))) {
                    throw new \LogicException(
                        sprintf(
                            "Cannot register encrypted host `%s`; unencrypted " .
                            "host `%s` registered on conflicting port `%s`",
                            ($new->IsEncrypted() ? $new->getName() : $old->getName()) ?: "*",
                            ($new->IsEncrypted() ? $old->getName() : $new->getName()) ?: "*",
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
            $generic = $this->httpDrivers[$port][\strlen(inet_pton($address)) === 4 ? "0.0.0.0" : "::"] ?? $driver;
            if (($this->httpDrivers[$port][$address] ?? $generic) !== $driver) {
                throw new \LogicException(
                    "Cannot use two different HttpDriver instances on an equivalent address-port pair"
                );
            }
            if ($address == "0.0.0.0" || $address == "::") {
                foreach ($this->httpDrivers[$port] ?? [] as $oldAddr => $oldDriver) {
                    if ($oldDriver !== $driver && (\strlen(inet_pton($address)) === 4) == ($address == "0.0.0.0")) {
                        throw new \LogicException(
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
            throw new \LogicException("Can setup http drivers only once");
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
     * Select the suited HttpDriver instance, filtered by address and port pair
     */
    public function selectHttpDriver($address, $port) {
        return $this->httpDrivers[$port][$address] ??
            $this->httpDrivers[$port][\strlen(inet_pton($address)) === 4 ? "0.0.0.0" : "::"];
    }

    /**
     * Select a virtual host match for the specified request according to RFC 7230 criteria
     *
     * @param \Aerys\InternalRequest $ireq
     * @return Vhost|null Returns a Vhost object and boolean TRUE if a valid host selected, FALSE otherwise
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec5.html#sec5.2
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec19.html#sec19.6.1.1
     */
    public function selectHost(InternalRequest $ireq) {
        if (isset($ireq->uriHost)) {
            return $this->selectHostByAuthority($ireq);
        } else {
            return null;
        }

        // If null is returned a stream must return 400 for HTTP/1.1 requests and use the default
        // host for HTTP/1.0 requests.
    }

    /**
     * Retrieve the group's default host
     *
     * @return \Aerys\Vhost
     */
    public function getDefaultHost(): Vhost {
        if ($this->defaultHost) {
            return $this->defaultHost;
        } elseif ($this->cachedVhostCount) {
            return current($this->vhosts);
        } else {
            throw new \LogicException(
                "Cannot retrieve default host; no Vhost instances added to the group"
            );
        }
    }

    private function selectHostByAuthority(InternalRequest $ireq) {
        $explicitHostId = "{$ireq->uriHost}:{$ireq->uriPort}";
        $wildcardHost = "0.0.0.0:{$ireq->uriPort}";
        $ipv6WildcardHost = "[::]:{$ireq->uriPort}";

        if (isset($this->vhosts[$explicitHostId])) {
            $vhost = $this->vhosts[$explicitHostId];
        } elseif (isset($this->vhosts[$wildcardHost])) {
            $vhost = $this->vhosts[$wildcardHost];
        } elseif (isset($this->vhosts[$ipv6WildcardHost])) {
            $vhost = $this->vhosts[$ipv6WildcardHost];
        } elseif ($this->cachedVhostCount !== 1) {
            return null;
        } else {
            $ipComparison = $ireq->uriHost;

            if (!@inet_pton($ipComparison)) {
                $ipComparison = substr($ipComparison, 1, -1); // IPv6 braces
                if (!@inet_pton($ipComparison)) {
                    return null;
                }
            }
            if (!(($vhost = $this->getDefaultHost()) && in_array($ireq->uriPort, $vhost->getPorts($ipComparison)))) {
                return null;
            }
        }

        // IMPORTANT: Wildcard IP hosts without names that are running both encrypted and plaintext
        // apps on the same interface (via separate ports) must be checked for encryption to avoid
        // displaying unencrypted data as a result of carefully crafted Host headers. This is an
        // extreme edge case but it's potentially exploitable without this check.
        // DO NOT REMOVE THIS UNLESS YOU'RE SURE YOU KNOW WHAT YOU'RE DOING.
        if ($vhost->isEncrypted() != $ireq->client->isEncrypted) {
            return null;
        }

        return $vhost;
    }

    /**
     * Retrieve an array of unique socket addresses on which hosts should listen
     *
     * @return array Returns an array of unique host addresses in the form: tcp://ip:port
     */
    public function getBindableAddresses(): array {
        return array_unique(array_merge(...array_values(array_map(function($vhost) {
            return $vhost->getBindableAddresses();
        }, $this->vhosts))));
    }

    /**
     * Retrieve stream encryption settings by bind address
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
            "defaultHost" => $this->defaultHost,
        ];
    }
    
    public function monitor(): array {
        return array_map(function ($vhost) { return $vhost->monitor(); }, $this->vhosts);
    }
}

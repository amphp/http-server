<?php

namespace Aerys;

use Amp\{
    Promise,
    function any
};

class VhostContainer implements \Countable {
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
     * @TODO Validate to prevent conflicts between wildcard and specific IPs
     */
    public function use(Vhost $vhost) {
        $this->preventCryptoSocketConflict($vhost);
        foreach ($vhost->getIds() as $id) {
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
     * Select a virtual host match for the specified request according to RFC 2616 criteria
     *
     * @param \Aerys\InternalRequest $ireq
     * @return Vhost|null Returns a Vhost object and boolean TRUE if a valid host selected, FALSE otherwise
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec5.html#sec5.2
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec19.html#sec19.6.1.1
     */
    public function selectHost(InternalRequest $ireq) {
        if (stripos($ireq->uriRaw, "http://") === 0 || stripos($ireq->uriRaw, "https://") === 0) {
            return $this->selectHostByAbsoluteUri($ireq);
        } elseif (empty($ireq->headers["host"])) {
            return null;
        } else {
            return $this->selectHostByAuthority($ireq, $ireq->headers["host"][0]);
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

    /**
     * @TODO How to handle absolute URIs for forward proxy use-cases? The best way to do this is
     *       probably to set a "proxy mode" flag on the collection and disallow more than a single
     *       virtual host when in this mode.
     */
    private function selectHostByAbsoluteUri(InternalRequest $ireq) {
        $port = $ireq->uriPort ?? ($ireq->client->isEncrypted ? 443 : 80);
        $vhostId = "{$ireq->uriHost}:{$port}";

        return $this->selectHostByAuthority($ireq, $vhostId);
    }

    private function selectHostByAuthority(InternalRequest $ireq, string $explicitHostId) {
        if ($portStartPos = strrpos($explicitHostId, "]")) {
            $ipComparison = substr($explicitHostId, 1, $portStartPos - 1);
            $port = substr($explicitHostId, $portStartPos + 2);
            $port = ($port === FALSE) ? ($ireq->client->isEncrypted ? "443" : "80") : $port;
        } elseif ($portStartPos = strrpos($explicitHostId, ":")) {
            $ipComparison = substr($explicitHostId, 0, $portStartPos);
            $port = substr($explicitHostId, $portStartPos + 1);
        } else {
            $port = $ireq->client->isEncrypted ? "443" : "80";
            $ipComparison = $explicitHostId;
            $explicitHostId .= ":{$port}";
        }

        $wildcardHost = "0.0.0.0:{$port}";
        $ipv6WildcardHost = "[::]:{$port}";

        if (isset($this->vhosts[$explicitHostId])) {
            $vhost = $this->vhosts[$explicitHostId];
        } elseif (isset($this->vhosts[$wildcardHost])) {
            $vhost = $this->vhosts[$wildcardHost];
        } elseif (isset($this->vhosts[$ipv6WildcardHost])) {
            $vhost = $this->vhosts[$ipv6WildcardHost];
        } elseif ($this->cachedVhostCount !== 1) {
            $vhost = null;
        } elseif (!@inet_pton($ipComparison)) {
            $vhost = null;
        } elseif (!(($vhost = $this->getDefaultHost()) && $vhost->getPorts($ipComparison))) {
            $vhost = null;
        }

        // IMPORTANT: Wildcard IP hosts without names that are running both encrypted and plaintext
        // apps on the same interface (via separate ports) must be checked for encryption to avoid
        // displaying unencrypted data as a result of carefully crafted Host headers. This is an
        // extreme edge case but it's potentially exploitable without this check.
        // DO NOT REMOVE THIS UNLESS YOU'RE SURE YOU KNOW WHAT YOU'RE DOING.
        if ($vhost && $vhost->isEncrypted() != $ireq->client->isEncrypted) {
            $vhost = null;
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
}

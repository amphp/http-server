<?php

namespace Aerys;

/**
 * The VhostGroup class aggregates the individual virtual hosts exposed by Server instances in
 * one place. The collection encapsulates RFC2616-compliant logic for selecting which Vhost should
 * service individual requests in multi-host environments.
 */
class VhostGroup implements \Countable {
    private $vhosts = [];
    private $singleHost;

    /**
     * Add a host to the collection
     *
     * @param \Aerys\Vhost The host instance to add
     * @return int Returns the number of hosts in the collection after the addition
     * @TODO Validate to prevent conflicts between wildcard and specific IPs
     */
    public function addHost(Vhost $vhost) {
        $vhostId = $vhost->getId();
        $this->vhosts[$vhostId] = $vhost;
        $vhostCount = count($this->vhosts);
        $this->singleHost = ($vhostCount > 1) ? null : $vhost;
        $this->preventCryptoSocketConflict($vhost);

        return $vhostCount;
    }

    private function preventCryptoSocketConflict(Vhost $vhost) {
        $newHostIsEncrypted = $vhost->isEncrypted();
        foreach ($this->vhosts as $existing) {
            if ($vhost === $existing || ($newHostIsEncrypted + $existing->isEncrypted()) != 1) {
                continue;
            }
            $address = $existing->getAddress();
            if ($vhost->matchesAddress($address) && ($existing->getPort() == $vhost->getPort())) {
                throw new BootException(
                    sprintf(
                        'Cannot register encrypted host `%s`; unencrypted ' .
                        'host `%s` registered on conflicting port `%s`',
                        $newHostIsEncrypted ? $vhost->getId() : $existing->getId(),
                        $newHostIsEncrypted ? $existing->getId() : $vhost->getId(),
                        $vhost->getAddress() . ':' . $vhost->getPort()
                    )
                );
            }
        }
    }

    /**
     * Select a virtual host match for the specified request according to RFC 2616 criteria
     *
     * @param \Aerys\RequestCycle $cycle
     * @return array Returns a Vhost object and boolean TRUE if a valid host selected, FALSE otherwise
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec5.html#sec5.2
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec19.html#sec19.6.1.1
     */
    public function selectHost(RequestCycle $cycle, $defaultHostId = NULL) {
        if ($this->singleHost) {
            // If a server only exposes one host we don't bother with host header validation and
            // return our single host directly. This behavior is justified in RFC2616 Section 5.2:
            // > An origin server that does not allow resources to differ by the requested host MAY
            // > ignore the Host header field value when determining the resource identified by an
            // > HTTP/1.1 request.
            $vhost = $this->singleHost;
        } elseif ($cycle->hasAbsoluteUri) {
            $vhost = $this->selectHostByAbsoluteUri($cycle);
        } elseif ($cycle->protocol == '1.1' || !empty($cycle->headers['HOST'])) {
            $vhost = $this->selectHostByHeader($cycle);
        } elseif ($cycle->protocol == '1.0') {
            $vhost = $this->selectDefaultHost($defaultHostId);
        }

        if (isset($vhost)) {
            $isRequestedHostValid = TRUE;
        } else {
            $vhost = $this->selectDefaultHost($defaultHostId);
            $isRequestedHostValid = FALSE;
        }

        return [$vhost, $isRequestedHostValid];
    }

    /**
     * @TODO How to handle absolute URIs for forward proxy use-cases? The best way to do this is
     *       probably to set a "proxy mode" flag on the collection and disallow more than a single
     *       virtual host when in this mode.
     */
    private function selectHostByAbsoluteUri(RequestCycle $cycle) {
        if (!$port = $cycle->uriPort) {
            $port = $cycle->client->isEncrypted ? 443 : 80;
        }

        $vhostId = "{$cycle->uriHost}:{$port}";

        return isset($this->vhosts[$vhostId]) ? $this->vhosts[$vhostId] : NULL;
    }

    private function selectHostByHeader(RequestCycle $cycle) {
        $hostHeader = $cycle->headers['HOST'][0];
        $isEncrypted = $cycle->client->isEncrypted;

        if ($portStartPos = strrpos($hostHeader, ']')) {
            $ipComparison = substr($hostHeader, 0, $portStartPos + 1);
            $port = substr($hostHeader, $portStartPos + 2);
            $port = ($port === FALSE) ? ($isEncrypted ? '443' : '80') : $port;
        } elseif ($portStartPos = strrpos($hostHeader, ':')) {
            $ipComparison = substr($hostHeader, 0, $portStartPos);
            $port = substr($hostHeader, $portStartPos + 1);
        } else {
            $port = $isEncrypted ? '443' : '80';
            $ipComparison = $hostHeader;
            $hostHeader .= ":{$port}";
        }

        $wildcardHost = "*:{$port}";
        $ipv6WildcardHost = "[::]:{$port}";

        if (isset($this->vhosts[$hostHeader])) {
            $vhost = $this->vhosts[$hostHeader];
        } elseif (isset($this->vhosts[$wildcardHost])) {
            $vhost = $this->vhosts[$wildcardHost];
        } elseif (isset($this->vhosts[$ipv6WildcardHost])) {
            $vhost = $this->vhosts[$ipv6WildcardHost];
        } else {
            $vhost = $this->attemptIpHostSelection($hostHeader, $ipComparison);
        }

        // IMPORTANT: Wildcard IP hosts without names that are running both encrypted and plaintext
        // apps on the same interface (via separate ports) must be checked for encryption to avoid
        // displaying unencrypted data as a result of carefully crafted Host headers. This is an
        // extreme edge case but it's potentially exploitable without this check.
        // DO NOT REMOVE THIS UNLESS YOU'RE SURE YOU KNOW WHAT YOU'RE DOING.
        if ($vhost && $vhost->isEncrypted() && !$isEncrypted) {
            $vhost = NULL;
        }

        return $vhost;
    }

    private function attemptIpHostSelection($hostHeader, $ipComparison) {
        if (count($this->vhosts) !== 1) {
            $vhost = NULL;
        } elseif (!@inet_pton($ipComparison)) {
            $vhost = NULL;
        } elseif (!(($vhost = current($this->vhosts))
            && ($vhost->getAddress() === $ipComparison || $vhost->hasWildcardAddress())
        )) {
            $vhost = NULL;
        }

        return $vhost;
    }

    /**
     * Return the fallback host when no other hosts can be matched
     *
     * @return \Aerys\Vhost
     */
    public function selectDefaultHost($defaultHostId) {
        return isset($this->vhosts[$defaultHostId])
            ? $this->vhosts[$defaultHostId]
            : current($this->vhosts);
    }

    /**
     * Retrieve an array of unique socket addresses on which hosts should listen
     *
     * @return array Returns an array of unique host addresses in the form: tcp://ip:port
     */
    public function getBindableAddresses() {
        return array_unique(array_map(function($vhost) {
            return $vhost->getBindableAddress();
        }, $this->vhosts));
    }

    /**
     * Retrieve stream encryption settings by bind address
     *
     * @param return array
     */
    public function getTlsBindingsByAddress() {
        $bindMap = [];
        $sniNameMap = [];
        foreach ($this->vhosts as $vhost) {
            if (!$vhost->isEncrypted()) {
                continue;
            }

            $bindAddress = $vhost->getBindableAddress();
            $contextArr = $vhost->getTlsContextArr();
            $bindMap[$bindAddress] = $contextArr;

            if ($vhost->hasName()) {
                $sniNameMap[$bindAddress][$vhost->getName()] = $contextArr['local_cert'];
            }
        }

        // We use current($this->vhosts) in places so it's important to reset the array's internal
        // pointer after iterating above.
        reset($this->vhosts);

        // If we have multiple different TLS certs on the same bind address we need to assign
        // the "SNI_server_name" key to enable the SNI extension.
        foreach (array_keys($bindMap) as $bindAddress) {
            if (isset($sniNameMap[$bindAddress]) && count($sniNameMap[$bindAddress]) > 1) {
                $bindMap[$bindAddress]['SNI_server_certs'] = $sniNameMap[$bindAddress];
            }
        }

        return $bindMap;
    }

    public function count() {
        return count($this->vhosts);
    }
}

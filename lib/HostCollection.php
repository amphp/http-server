<?php

namespace Aerys;

/**
 * The HostCollection class aggregates the individual virtual hosts exposed by Server instances in
 * one place. The collection encapsulates RFC2616-compliant logic for selecting which Host should
 * service individual requests in multi-host environments.
 */
class HostCollection implements \Countable {
    private $hosts = [];
    private $singleHost;

    /**
     * Add a host to the collection
     *
     * @param \Aerys\Host The host instance to add
     * @return int Returns the number of hosts in the collection after the addition
     * @TODO Validate to prevent conflicts between wildcard and specific IPs
     */
    public function addHost(Host $host) {
        $hostId = $host->getId();
        $this->hosts[$hostId] = $host;
        $hostCount = count($this->hosts);

        if ($hostCount === 1) {
            $this->singleHost = $host;
        } else {
            $this->singleHost = NULL;
        }

        return $hostCount;
    }

    /**
     * Select a virtual host match for the specified request according to RFC 2616 criteria
     *
     * @param \Aerys\Cycle $cycle
     * @return array Returns a Host object and boolean TRUE if a valid host selected, FALSE otherwise
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec5.html#sec5.2
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec19.html#sec19.6.1.1
     */
    public function selectHost(Cycle $cycle, $defaultHostId = NULL) {
        if ($this->singleHost) {
            // If a server only exposes one host we don't bother with host header validation and
            // return our single host directly. This behavior is justified in RFC2616 Section 5.2:
            // > An origin server that does not allow resources to differ by the requested host MAY
            // > ignore the Host header field value when determining the resource identified by an
            // > HTTP/1.1 request.
            $host = $this->singleHost;
        } elseif ($cycle->hasAbsoluteUri) {
            $host = $this->selectHostByAbsoluteUri($cycle);
        } elseif ($cycle->protocol == '1.1' || !empty($cycle->ucHeaders['HOST'])) {
            $host = $this->selectHostByHeader($cycle);
        } elseif ($cycle->protocol == '1.0') {
            $host = $this->selectDefaultHost($defaultHostId);
        }

        if ($host) {
            $isRequestedHostValid = TRUE;
        } else {
            $host = $this->selectDefaultHost($defaultHostId);
            $isRequestedHostValid = FALSE;
        }

        return [$host, $isRequestedHostValid];
    }

    /**
     * @TODO How to handle absolute URIs for forward proxy use-cases? The best way to do this is
     *       probably to set a "proxy mode" flag on the collection and disallow more than a single
     *       virtual host when in this mode.
     */
    private function selectHostByAbsoluteUri(Cycle $cycle) {
        if (!$port = $cycle->uriPort) {
            $port = $cycle->isEncrypted ? 443 : 80;
        }

        $hostId = "{$cycle->uriHost}:{$port}";

        return isset($this->hosts[$hostId]) ? $this->hosts[$hostId] : NULL;
    }

    private function selectHostByHeader(Cycle $cycle) {
        $hostHeader = $cycle->ucHeaders['HOST'][0];
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

        if (isset($this->hosts[$hostHeader])) {
            $host = $this->hosts[$hostHeader];
        } elseif (isset($this->hosts[$wildcardHost])) {
            $host = $this->hosts[$wildcardHost];
        } elseif (isset($this->hosts[$ipv6WildcardHost])) {
            $host = $this->hosts[$ipv6WildcardHost];
        } else {
            $host = $this->attemptIpHostSelection($hostHeader, $ipComparison);
        }

        // IMPORTANT: Wildcard IP hosts without names that are running both encrypted and plaintext
        // apps on the same interface (via separate ports) must be checked for encryption to avoid
        // displaying unencrypted data as a result of carefully crafted Host headers. This is an
        // extreme edge case but it's potentially exploitable without this check.
        // DO NOT REMOVE THIS UNLESS YOU'RE SURE YOU KNOW WHAT YOU'RE DOING.
        if ($host && $host->isEncrypted() && !$isEncrypted) {
            $host = NULL;
        }

        return $host;
    }

    private function attemptIpHostSelection($hostHeader, $ipComparison) {
        if (count($this->hosts) !== 1) {
            $host = NULL;
        } elseif (!filter_var($ipComparison, FILTER_VALIDATE_IP)) {
            $host = NULL;
        } elseif (!(($host = current($this->hosts))
            && ($host->getAddress() === $ipComparison || $host->hasWildcardAddress())
        )) {
            $host = NULL;
        }

        return $host;
    }

    /**
     * Return the fallback host when no other hosts can be matched
     *
     * @return \Aerys\Host
     */
    public function selectDefaultHost($defaultHostId) {
        return isset($this->hosts[$defaultHostId])
            ? $this->hosts[$defaultHostId]
            : current($this->hosts);
    }

    /**
     * Retrieve an array of unique socket addresses on which hosts should listen
     *
     * @return array Returns an array of unique host addresses in the form: tcp://ip:port
     */
    public function getBindableAddresses() {
        return array_unique(array_map(function($host) {
            return $host->getBindableAddress();
        }, $this->hosts));
    }

    /**
     * Retrieve stream encryption settings by bind address
     *
     * @param return array
     */
    public function getTlsBindingsByAddress() {
        $bindMap = [];
        foreach ($this->hosts as $host) {
            if ($host->isEncrypted()) {
                $bindAddress = $host->getBindableAddress();
                $contextArr = $host->getTlsContextArr();
                $bindMap[$bindAddress] = $contextArr;
            }
        }

        // We use current($this->hosts) in places so it's important to reset the array's internal
        // pointer after iterating above.
        reset($this->hosts);

        return $bindMap;
    }

    public function count() {
        return count($this->hosts);
    }
}

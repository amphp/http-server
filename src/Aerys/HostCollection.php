<?php

namespace Aerys;

/**
 * The HostCollection class aggregates the individual virtual hosts exposed by Server instances in
 * one place. The collection encapsulates logic for selecting which Host should be used to service
 * individual requests in multi-host environments. The HTTP/1.1 specification mandates somewhat
 * complex criteria for host selection and that logic is reflected here.
 */
class HostCollection implements \Countable, \IteratorAggregate {

    private $hosts = [];
    private $defaultHost;
    private $cachedHostCount = 0;

    /**
     * Add a host to the collection
     *
     * @param \Aerys\Host The host instance to add
     * @return int Returns the number of hosts in the collection after the addition
     * @TODO Validate to prevent conflicts between wildcard and specific IPs
     * @TODO Track which addresses have TLS enabled and prevent conflicts in multi-host environments
     */
    function addHost(Host $host) {
        $hostId = $host->getId();
        $this->hosts[$hostId] = $host;
        $this->cachedHostCount++;

        return count($this->hosts);
    }

    /**
     * Assign a default host for use in request host selection
     *
     * @param string $hostId
     * @throws \DomainException If no hosts in the collection match the specified ID
     * @return void
     */
    function setDefaultHost($hostId) {
        if (isset($this->hosts[$hostId])) {
            $this->defaultHost = $this->hosts[$hostId];
        } elseif ($hostId !== NULL) {
            throw new \DomainException(
                "Invalid default host; unknown host ID: {$hostId}"
            );
        }
    }

    /**
     * Retrieve the collection's default host ID
     *
     * @return mixed Returns string host ID or NULL if no default is assigned
     */
    function getDefaultHostId() {
        return $this->defaultHost ? $this->defaultHost->getId() : NULL;
    }

    /**
     * Select a virtual host match for the specified request according to RFC 2616 criteria
     *
     * @param \Aerys\Request $request
     * @return mixed Returns the matching \Aerys\Host instance or NULL if no match found
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec5.html#sec5.2
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec19.html#sec19.6.1.1
     */
    function selectRequestHost(Request $request) {
        if ($this->cachedHostCount === 1) {
            //
            // If a server only exposes one host we don't bother with host header validation and
            // return our single host directly. This behavior is justified in RFC2616 Section 5.2:
            //
            // > An origin server that does not allow resources to differ by the requested host MAY
            // > ignore the Host header field value when determining the resource identified by an
            // > HTTP/1.1 request.
            //
            $host = current($this->hosts);
        } elseif ($request->hasAbsoluteUri()) {
            $host = $this->selectHostByAbsoluteUri($request);
        } elseif ($request->getProtocol() >= 1.1 || $request->hasHeader('Host')) {
            $host = $this->selectHostByHeader($request);
        } else {
            $host = $this->selectDefaultHost();
        }

        return $host;
    }

    /**
     * @TODO How to handle absolute URIs for forward proxy use-cases? The best way to do this is
     *       probably to set a "proxy mode" flag on the collection and disallow more than a single
     *       virtual host when in this mode.
     */
    private function selectHostByAbsoluteUri(Request $request) {
        if (!$port = $request->getUriPort()) {
            $port = $request->isEncrypted() ? 443 : 80;
        }

        $hostId = sprintf("%s:%d", $request->getUriHost(), $port);

        return isset($this->hosts[$hostId])
            ? $this->hosts[$hostId]
            : NULL;
    }

    private function selectHostByHeader(Request $request) {
        $hostHeader = current($request->getHeader('Host'));
        $isEncrypted = $request->isEncrypted();

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
    function selectDefaultHost() {
        return $this->defaultHost ?: current($this->hosts);
    }

    /**
     * Retrieve an array of unique socket addresses on which hosts should listen
     *
     * @return array Returns an array of unique host addresses in the form: tcp://ip:port
     */
    function getBindableAddresses() {
        return array_unique(array_map(function($host) {
            return $host->getBindableAddress();
        }, $this->hosts));
    }

    /**
     * Retrieve stream encryption settings by bind address
     *
     * @param return array
     */
    function getTlsBindingsByAddress() {
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

    /**
     * Retrieve the number of hosts added to the collection
     *
     * @return int Returns the current host count
     */
    function count() {
        return $this->cachedHostCount;
    }

    /**
     * Retrieve an iterator instance for the aggregated Host instances
     *
     * @return \ArrayIterator Returns an iterator containing aggregated \Aerys\Host objects
     */
    function getIterator() {
        return new \ArrayIterator($this->hosts);
    }

}

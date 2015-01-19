<?php

namespace Aerys;

class HostDefinition {
    private $address;
    private $port;
    private $name;
    private $application;
    private $tlsContextArr = [];
    private $tlsDefaults = [
        'local_cert'            => null,
        'passphrase'            => null,
        'allow_self_signed'     => false,
        'verify_peer'           => false,
        'ciphers'               => null,
        'cafile'                => null,
        'capath'                => null,
        'single_ecdh_use'       => false,
        'ecdh_curve'            => 'prime256v1',
        'honor_cipher_order'    => true,
        'disable_compression'   => true,
        'reneg_limit'           => 0,
        'reneg_limit_callback'  => null,
        'crypto_method'         => STREAM_CRYPTO_METHOD_TLS_SERVER,
    ];

    public function __construct($address, $port, $name, callable $application) {
        $this->setAddress($address);
        $this->setPort($port);
        $this->name = strtolower($name);
        $this->id = ($this->name ? $this->name : $this->address) . ':' . $this->port;
        $this->application = $application;
    }

    private function setAddress($address) {
        $address = trim($address, "[]");
        if ($address === '*') {
            $this->address = $address;
        } elseif ($address === '::') {
            $this->address = '[::]';
        } elseif (!$packedAddress = @inet_pton($address)) {
            throw new \InvalidArgumentException(
                "IPv4, IPv6 or wildcard address required: {$address}"
            );
        } else {
            $this->address = isset($packedAddress[4]) ? $address : "[{$address}]";
        }
    }

    private function setPort($port) {
        if ($port != (string)(int) $port || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(
                "Invalid host port: {$port}; integer in the range [1-65535] required"
            );
        }

        $this->port = (int) $port;
    }

    /**
     * Retrieve the ID for this host
     *
     * @return string
     */
    public function getId() {
        return $this->id;
    }

    /**
     * Retrieve the IP on which the host listens (may be a wildcard "*" or "[::]")
     *
     * @return string
     */
    public function getAddress() {
        return $this->address;
    }

    /**
     * Retrieve the port on which this host listens
     *
     * @return int
     */
    public function getPort() {
        return $this->port;
    }

    /**
     * Retrieve the URI on which this host should be bound
     *
     * @return string
     */
    public function getBindableAddress() {
        $ip = ($this->address === '*') ? '0.0.0.0' : $this->address;

        return sprintf('tcp://%s:%d', $ip, $this->port);
    }

    /**
     * Retrieve the host's name
     *
     * @return string
     */
    public function getName() {
        return $this->name;
    }

    /**
     * Retrieve the callable application for this host
     *
     * @return mixed
     */
    public function getApplication() {
        return $this->application;
    }

    /**
     * Is this host's address defined by a wildcard character?
     *
     * @return bool
     */
    public function hasWildcardAddress() {
        return ($this->address === '*' || $this->address === '[::]');
    }

    /**
     * Does the specified IP address (or wildcard) match this host's address?
     *
     * @param string $address
     * @return bool
     */
    public function matchesAddress($address) {
        if ($this->address === '*' || $this->address === '[::]') {
            return true;
        }
        if ($address === '*' || $address === '[::]') {
            return true;
        }

        return (@inet_pton($this->address) === @inet_pton($address));
    }
    
    /**
     * Does this host have a name?
     *
     * @return bool
     */
    public function hasName() {
        return $this->name != '';
    }

    /**
     * Has this host been assigned a TLS encryption context?
     *
     * @return bool Returns true if a TLS context is assigned, false otherwise
     */
    public function isEncrypted() {
        return (bool) $this->tlsContextArr;
    }

    /**
     * Define TLS encryption settings for this host
     *
     * @param array An array mapping TLS stream context values
     * @link http://php.net/manual/en/context.ssl.php
     * @return void
     */
    public function setCrypto(array $tls) {
        $tls = array_merge($this->tlsDefaults, $tls);
        $tls = array_filter($tls, function($value) { return isset($value); });

        $this->tlsContextArr = $tls;
    }

    /**
     * Retrieve this host's TLS connection context options
     *
     * @return array An array of stream encryption context options
     */
    public function getTlsContextArr() {
        return $this->tlsContextArr;
    }

    /**
     * Determine if this host matches the specified HostDefinition ID string
     *
     * @param string $hostId
     * @return bool Returns true if a match is found, false otherwise
     */
    public function matches($hostId) {
        if ($hostId === $this->id || $hostId === '*') {
            $isMatch = true;
        } elseif (substr($hostId, 0, 2) === '*:') {
            $portToMatch = substr($hostId, 2);
            $isMatch = ($portToMatch === '*' || $this->port == $portToMatch);
        } elseif (substr($hostId, -2) === ':*') {
            $addrToMatch = substr($hostId, 0, -2);
            $isMatch = ($addrToMatch === '*' || $this->address === $addrToMatch || $this->name === $addrToMatch);
        } else {
            $isMatch = false;
        }

        return $isMatch;
    }

    /**
     * Simplify debug output
     *
     * @return array
     */
    public function __debugInfo() {
        $appType = is_object($this->application)
            ? get_class($this->application)
            : gettype($this->application);

        return [
            'address' => $this->address,
            'port' => $this->port,
            'name' => $this->name,
            'tls' => $this->tlsContextArr,
            'application' => $appType,
        ];
    }
}

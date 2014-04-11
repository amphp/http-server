<?php

namespace Aerys;

class Host {
    private $address;
    private $port;
    private $name;
    private $application;
    private $isTlsAvailable;
    private $tlsContextArr = [];
    private $tlsDefaults = [
        /*
        'single_ecdh_use'       => TRUE,
        'honor_cipher_order'    => TRUE,
        'disable_compression'   => TRUE,
        'reneg_limit'           => 0,
        'reneg_limit_callback'  => NULL,
        'SNI_server_names'      => [],
        */
        'local_cert'            => NULL,
        'passphrase'            => NULL,
        'allow_self_signed'     => FALSE,
        'verify_peer'           => FALSE,
        'ciphers'               => NULL,
        'cafile'                => NULL,
        'capath'                => NULL
    ];

    public function __construct($address, $port, $name, callable $application) {
        $this->setAddress($address);
        $this->setPort($port);
        $this->name = strtolower($name);
        $this->id = $this->name
            ? $this->name . ':' . $this->port
            : $this->address . ':' . $this->port;
        $this->application = $application;
        $this->isTlsAvailable = extension_loaded('openssl');
    }

    private function setAddress($address) {
        $address = trim($address, "[]");
        if ($address === '*') {
            $this->address = $address;
        } elseif ($address === '::') {
            $this->address = '[::]';
        } elseif (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $this->address = "[{$address}]";
        } elseif (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $this->address = $address;
        } else {
            throw new \InvalidArgumentException(
                "Valid IPv4/IPv6 address or wildcard required: {$address}"
            );
        }
    }

    private function setPort($port) {
        if ($port = filter_var($port, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 1,
            'max_range' => 65535
        ]])) {
            $this->port = (int) $port;
        } else {
            throw new \InvalidArgumentException(
                "Invalid host port: {$port}"
            );
        }
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
     * Does this host have a name?
     *
     * @return bool
     */
    public function hasName() {
        return ($this->name || $this->name === '0');
    }

    /**
     * Has this host been assigned a TLS encryption context?
     *
     * @return bool Returns TRUE if a TLS context is assigned, FALSE otherwise
     */
    public function isEncrypted() {
        return (bool) $this->tlsContextArr;
    }

    /**
     * Define TLS encryption settings for this host
     *
     * @param array An array mapping TLS stream context values
     * @link http://php.net/manual/en/context.ssl.php
     * @throws \RuntimeException If required PHP OpenSSL extension not loaded
     * @throws \InvalidArgumentException On missing local_cert key
     * @return void
     */
    public function setEncryptionContext(array $tlsDefinition) {
        if ($this->isTlsAvailable) {
            $this->tlsContextArr = $tlsDefinition ? $this->generateTlsContext($tlsDefinition) : [];
        } else {
            throw new \RuntimeException(
                sprintf('Cannot enable crypto on %s; openssl extension required', $this->id)
            );
        }
    }

    private function generateTlsContext(array $tls) {
        $tls = array_filter($tls, function($value) { return isset($value); });
        $tls = array_merge($this->tlsDefaults, $tls);

        if (empty($tls['local_cert'])) {
            throw new \InvalidArgumentException(
                '"local_cert" key required to bind crypto-enabled server socket'
            );
        } elseif (!(is_file($tls['local_cert']) && is_readable($tls['local_cert']))) {
            throw new \InvalidArgumentException(
                "Certificate file not found: {$tls['local_cert']}"
            );
        }

        return ['ssl' => $tls];
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
     * Determine if this host matches the specified Host ID string
     *
     * @param string $hostId
     * @return bool Returns TRUE if a match is found, FALSE otherwise
     */
    public function matches($hostId) {
        if ($hostId === $this->id || $hostId === '*') {
            $isMatch = TRUE;
        } elseif (substr($hostId, 0, 2) === '*:') {
            $portToMatch = substr($hostId, 2);
            $isMatch = ($portToMatch === '*' || $this->port == $portToMatch);
        } elseif (substr($hostId, -2) === ':*') {
            $addrToMatch = substr($hostId, 0, -2);
            $isMatch = ($addrToMatch === '*' || $this->address === $addrToMatch || $this->name === $addrToMatch);
        } else {
            $isMatch = FALSE;
        }

        return $isMatch;
    }
}

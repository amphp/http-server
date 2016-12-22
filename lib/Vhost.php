<?php

namespace Aerys;

class Vhost implements Monitor {
    private $application;
    private $interfaces;
    private $addressMap;
    private $name;
    private $ids;
    private $filters = [];
    private $monitors = [];
    private $httpDriver;
    private $tlsContextArr = [];
    private $tlsDefaults = [
        "local_cert"            => null,
        "passphrase"            => null,
        "allow_self_signed"     => false,
        "verify_peer"           => false,
        "ciphers"               => null,
        "cafile"                => null,
        "capath"                => null,
        "single_ecdh_use"       => false,
        "ecdh_curve"            => "prime256v1",
        "honor_cipher_order"    => true,
        "disable_compression"   => true,
        "reneg_limit"           => 0,
        "reneg_limit_callback"  => null,
        "crypto_method"         => STREAM_CRYPTO_METHOD_SSLv23_SERVER, /* means: TLS 1.0 and up */
    ];

    private static $cryptoMethodMap = [
        "tls"       => STREAM_CRYPTO_METHOD_SSLv23_SERVER, // no, not STREAM_CRYPTO_METHOD_TLS_SERVER, which is equivalent to TLS v1.0 only
        "tls1"      => STREAM_CRYPTO_METHOD_TLSv1_0_SERVER,
        "tlsv1"     => STREAM_CRYPTO_METHOD_TLSv1_0_SERVER,
        "tlsv1.0"   => STREAM_CRYPTO_METHOD_TLSv1_0_SERVER,
        "tls1.1"    => STREAM_CRYPTO_METHOD_TLSv1_1_SERVER,
        "tlsv1.1"   => STREAM_CRYPTO_METHOD_TLSv1_1_SERVER,
        "tls1.2"    => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER,
        "tlsv1.2"   => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER,
        "ssl2"      => STREAM_CRYPTO_METHOD_SSLv2_SERVER,
        "sslv2"     => STREAM_CRYPTO_METHOD_SSLv2_SERVER,
        "ssl3"      => STREAM_CRYPTO_METHOD_SSLv3_SERVER,
        "sslv3"     => STREAM_CRYPTO_METHOD_SSLv3_SERVER,
        "any"       => STREAM_CRYPTO_METHOD_ANY_SERVER,
    ];

    /** @Note Vhosts do not allow wildcards, only separate 0.0.0.0 and :: */
    public function __construct(string $name, array $interfaces, callable $application, array $filters, array $monitors = [], HttpDriver $driver = null) {
        $this->name = strtolower($name);
        if (!$interfaces) {
            throw new \InvalidArgumentException(
                "At least one interface must be passed, an empty interfaces array is not allowed"
            );
        }
        foreach ($interfaces as $interface) {
            $this->addInterface($interface);
        }
        $this->application = $application;
        $this->filters = array_values($filters);
        $this->monitors = $monitors;
        $this->httpDriver = $driver;

        if (self::hasAlpnSupport()) {
            $this->tlsDefaults["alpn_protocols"] = "h2";
        }

        if ($this->name !== '') {
            $addresses = [$this->name];
        } else {
            $addresses = array_unique(array_column($interfaces, 0));
        }
        $ports = array_unique(array_column($interfaces, 1));
        foreach ($addresses as $address) {
            if (strpos($address, ":") !== false) {
                $address = "[$address]";
            }
            foreach ($ports as $port) {
                $this->ids[] = "$address:$port";
            }
        }
    }

    private function addInterface(array $interface) {
        list($address, $port) = $interface;

        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(
                "Invalid host port: {$port}; integer in the range [1-65535] required"
            );
        }

        if (!$packedAddress = @inet_pton($address)) {
            throw new \InvalidArgumentException(
                "IPv4 or IPv6 address required: {$address}"
            );
        }

        $this->interfaces[] = [$address, $port];
        $this->addressMap[$packedAddress][] = $port;
    }

    /**
     * Retrieve the name:port IDs for this host
     *
     * @return array<string>
     */
    public function getIds(): array {
        return $this->ids;
    }

    /**
     * Retrieve the list of address-port pairs on which this host listens (address may be a wildcard "0.0.0.0" or "[::]")
     *
     * @return array<array<string, int>>
     */
    public function getInterfaces(): array {
        return $this->interfaces;
    }

    /**
     * Retrieve the URIs on which this host should be bound
     *
     * @return array
     */
    public function getBindableAddresses(): array {
        return array_map(static function($interface) {
            list($address, $port) = $interface;
            if (strpos($address, ":") !== false) {
                $address = "[$address]";
            }
            return "tcp://$address:$port";
        }, $this->interfaces);
    }

    /**
     * Retrieve the host's name (may be an empty string)
     *
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * Retrieve the host's callable application
     *
     * @return callable
     */
    public function getApplication(): callable {
        return $this->application;
    }

    /**
     * @param string $address
     * @return array<int> The list of listening ports on this address
     */
    public function getPorts(string $address): array {
        if ($address === '0.0.0.0' || $address === '::') {
            $ports = [];
            foreach ($this->addressMap as $packedAddress => $port_list) {
                if (\strlen($packedAddress) === ($address === '0.0.0.0' ? 4 : 16)) {
                    $ports = array_merge($ports, $port_list);
                }
            }
            return $ports;
        }

        $packedAddress = inet_pton($address); // if this yields a warning, there's something else buggy, but no @ missing.
        $wildcard = \strlen($packedAddress) === 4 ? "\0\0\0\0" : "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";
        if (!isset($this->addressMap[$wildcard])) {
            return $this->addressMap[$packedAddress] ?? [];
        } elseif (!isset($this->addressMap[$packedAddress])) {
            return $this->addressMap[$wildcard];
        } else {
            return array_merge($this->addressMap[$wildcard], $this->addressMap[$packedAddress]);
        }
    }

    public function getHttpDriver() {
        return $this->httpDriver;
    }

    /**
     * Does this host have a name?
     *
     * @return bool
     */
    public function hasName(): bool {
        return ($this->name !== "");
    }

    /**
     * Has this host been assigned a TLS encryption context?
     *
     * @return bool Returns true if a TLS context is assigned, false otherwise
     */
    public function isEncrypted(): bool {
        return (bool) $this->tlsContextArr;
    }

    /**
     * Define TLS encryption settings for this host
     *
     * @param array $tls An array mapping TLS stream context values
     * @link http://php.net/manual/en/context.ssl.php
     * @return void
     */
    public function setCrypto(array $tls) {
        if (!extension_loaded('openssl')) {
            throw new \LogicException(
                "Cannot assign crypto settings in host `{$this}`; ext/openssl required"
            );
        }

        $certPath = $tls['local_cert'];
        $certBase = basename($certPath);
        if (!$rawCert = @file_get_contents($certPath)) {
            throw new \RuntimeException(
                "TLS certificate path `{$certPath}` could not be read in host `{$this}`"
            );
        }

        if (!$cert = @openssl_x509_read($rawCert)) {
            throw new \RuntimeException(
                "`{$certBase}` does not appear to be a valid X.509 certificate in host `{$this}`"
            );
        }

        if (!isset($tls['local_pk']) && !preg_match("#-----BEGIN( [A-Z]+)? PRIVATE KEY-----#", $rawCert)) {
            throw new \RuntimeException(
                "TLS certificate `{$certBase}` appears to be missing the private key in host " .
                "`{$this}`; encrypted hosts must concatenate their private key into the same " .
                "file with the public key and any intermediate CA certs or use the local_pk option."
            );
        }

        if (!$cert = openssl_x509_parse($cert)) {
            throw new \RuntimeException(
                "Failed parsing X.509 certificate `{$certBase}` in host `{$this}`"
            );
        }

        $names = $this->parseNamesFromTlsCertArray($cert);
        if ($this->name != "" && !in_array($this->name, $names)) {
            trigger_error(
                "TLS certificate `{$certBase}` has no CN or SAN name match for host `{$this}`; " .
                "web browsers will not trust the validity of your certificate :(",
                E_USER_WARNING
            );
        }

        if (time() > $cert['validTo_time_t']) {
            date_default_timezone_set(@date_default_timezone_get());
            $expiration = date('Y-m-d', $cert['validTo_time_t']);
            trigger_error(
                "TLS certificate `{$certBase}` for host `{$this}` expired {$expiration}; web " .
                "browsers will not trust the validity of your certificate :(",
                E_USER_WARNING
            );
        }

        if (isset($tls['crypto_method'])) {
            $tls = $this->normalizeTlsCryptoMethod($tls);
        }

        $tls = array_merge($this->tlsDefaults, $tls);
        $tls = array_filter($tls, function($value) { return isset($value); });

        $this->tlsContextArr = $tls;
    }

    private function parseNamesFromTlsCertArray(array $cert): array {
        $names = [];
        if (!empty($cert['subject']['CN'])) {
            $names[] = $cert['subject']['CN'];
        }

        if (empty($cert["extensions"]["subjectAltName"])) {
            return $names;
        }

        $parts = array_map('trim', explode(',', $cert["extensions"]["subjectAltName"]));
        foreach ($parts as $part) {
            if (stripos($part, 'DNS:') === 0) {
                $names[] = substr($part, 4);
            }
        }

        return array_map('strtolower', $names);
    }

    private function normalizeTlsCryptoMethod(array $tls): array {
        $cryptoMethod = $tls['crypto_method'];

        if (is_string($cryptoMethod)) {
            $cryptoMethodArray = explode(' ', strtolower($cryptoMethod));
        } elseif (is_array($cryptoMethod)) {
            $cryptoMethodArray = array_map("strtolower", $cryptoMethod);
        } else {
            throw new \DomainException(
                sprintf('Invalid crypto method type: %s. String or array required', gettype($cryptoMethod))
            );
        }

        $bitmask = 0;
        foreach ($cryptoMethodArray as $method) {
            if (isset(self::$cryptoMethodMap[$method])) {
                $bitmask |= self::$cryptoMethodMap[$method];
            }
        }

        if (empty($bitmask)) {
            throw new \DomainException(
                'Invalid crypto method value: no valid methods found'
            );
        }

        $tls['crypto_method'] = $bitmask;

        return $tls;
    }

    /**
     * @see https://wiki.openssl.org/index.php/Manual:OPENSSL_VERSION_NUMBER(3)
     * @return bool
     */
    private static function hasAlpnSupport(): bool {
        if (!defined("OPENSSL_VERSION_NUMBER")) {
            return false;
        }

        return \OPENSSL_VERSION_NUMBER >= 0x10002000;
    }

    /**
     * Retrieve this host's TLS connection context options
     *
     * @return array An array of stream encryption context options
     */
    public function getTlsContextArr(): array {
        return $this->tlsContextArr;
    }

    /**
     * Retrieve filters registered for this host
     *
     * @return array
     */
    public function getFilters(): array {
        return $this->filters;
    }

    /**
     * Returns the host name
     *
     * @return string
     */
    public function __toString(): string {
        return $this->name;
    }

    /**
     * Simplify debug output
     *
     * @return array
     */
    public function __debugInfo(): array {
        $appType = is_object($this->application)
            ? get_class($this->application)
            : gettype($this->application);

        return [
            "interfaces" => $this->interfaces,
            "name" => $this->name,
            "tls" => $this->tlsContextArr,
            "application" => $appType,
        ];
    }

    public function monitor(): array {
        $handlers = [];
        foreach ($this->monitors as $class => $monitors) {
            $handlers[$class] = array_map(function ($montior) { return $montior->monitor(); }, $monitors);
        }
        return [
            "interfaces" => $this->interfaces,
            "name" => $this->name,
            "tls" => $this->tlsContextArr,
            "handlers" => $handlers,
        ];
    }
}

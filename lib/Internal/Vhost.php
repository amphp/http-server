<?php

namespace Aerys\Internal;

use Aerys\Monitor;
use Aerys\Responder;
use Amp\Socket\ServerTlsContext;

class Vhost implements Monitor {
    private $responder;
    private $interfaces;
    private $addressMap;
    private $name;
    private $ids;
    private $monitors = [];
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

    /** @Note Vhosts do not allow wildcards, only separate 0.0.0.0 and :: */
    public function __construct(string $name, array $interfaces, Responder $responder, array $middlewares, array $monitors = []) {
        $this->name = strtolower($name);

        if (!$interfaces) {
            throw new \Error(
                "At least one interface must be passed, an empty interfaces array is not allowed"
            );
        }

        foreach ($interfaces as $interface) {
            $this->addInterface($interface);
        }

        $this->responder = makeMiddlewareResponder($responder, $middlewares);
        $this->monitors = $monitors;

        if (self::hasAlpnSupport()) {
            $this->tlsDefaults["alpn_protocols"] = "h2";
        }

        $name = explode(":", $this->name)[0];
        $namePort = substr(strstr($this->name, ":"), 1);

        foreach ($this->interfaces as list($address, $port)) {
            if (strpos($address, ":") !== false) {
                $address = "[$address]";
            }

            $this->ids[] = $name . ":" . ($namePort === false ? $port : (int) $namePort) . ":$address:$port";
        }
    }

    private function addInterface(array $interface) {
        list($address, $port) = $interface;

        $isUnixSocket = $address[0] == "/";

        if ($isUnixSocket) {
            if ($port != 0) {
                throw new \Error(
                    "Invalid host port: {$port}; must be 0 for unix domain sockets"
                );
            }
        } elseif ($port < 1 || $port > 65535) {
            throw new \Error(
                "Invalid host port: {$port}; integer in the range [1-65535] required"
            );
        }

        if ($isUnixSocket) {
            $socketPath = realpath($address);
            if ($socketPath) {
                $address = $socketPath;
            }

            $dir = realpath(dirname($address));
            if (!$dir || !is_dir($dir)) {
                throw new \Error(
                    "Unix domain socket path is not in an existing or reachable directory"
                );
            } elseif (!is_readable($dir) || !is_writable($dir) || !is_executable($dir)) {
                throw new \Error(
                    "Unix domain socket path is in a directory without read, write and execute permissions"
                );
            }

            $packedAddress = $address = $dir. "/" .basename($address);
        } elseif ($packedAddress = @inet_pton($address)) {
            $address = inet_ntop($packedAddress);
        } else {
            throw new \Error(
                "IPv4 or IPv6 address or unix domain socket path required: {$address}"
            );
        }

        if (isset($this->addressMap[$packedAddress]) && in_array($port, $this->addressMap[$packedAddress])) {
            throw new \Error(
                "There must be no two identical interfaces for a same host"
            );
        }

        $this->interfaces[] = [$address, $port];
        $this->addressMap[$packedAddress][] = $port;
    }

    /**
     * Retrieve the name:port IDs for this host.
     *
     * @return array<string>
     */
    public function getIds(): array {
        return $this->ids;
    }

    /**
     * Retrieve the list of address-port pairs on which this host listens (address may be a wildcard "0.0.0.0" or "[::]").
     *
     * @return array<array<string, int>>
     */
    public function getInterfaces(): array {
        return $this->interfaces;
    }

    /**
     * Retrieve the URIs on which this host should be bound.
     *
     * @return array
     */
    public function getBindableAddresses(): array {
        return array_map(static function ($interface) {
            list($address, $port) = $interface;
            if ($address[0] == "/") { // unix domain socket
                return "unix://$address";
            }
            if (strpos($address, ":") !== false) {
                $address = "[$address]";
            }
            return "tcp://$address:$port";
        }, $this->interfaces);
    }

    /**
     * Retrieve the host's name (may be an empty string).
     *
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * Retrieve the Responder instance for this virtual host.
     *
     * @return callable
     */
    public function getResponder(): Responder {
        return $this->responder;
    }

    /**
     * @param string $address
     * @return array<int> The list of listening ports on this address
     */
    public function getPorts(string $address): array {
        if ($address[0] === "/") { // unix domain socket, no wildcards here
            return $this->addressMap[$address] ?? [];
        }

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
        }
        return array_merge($this->addressMap[$packedAddress], $this->addressMap[$wildcard]);
    }

    /**
     * Does this host have a name?
     *
     * @return bool
     */
    public function hasName(): bool {
        return $this->name !== "*" && strstr($this->name, ":", true) !== "*";
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
     * Define TLS encryption settings for this host.
     *
     * @param ServerTlsContext $tls
     * @link http://php.net/manual/en/context.ssl.php
     * @return void
     */
    public function setCrypto(ServerTlsContext $tls) {
        if (!extension_loaded('openssl')) {
            throw new \Error(
                "Cannot assign crypto settings in host `{$this}`; ext/openssl required"
            );
        }

        $tls = $tls->toStreamContextArray()['ssl'];

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
        if ($this->hasName() && !in_array(explode(":", $this->name)[0], $names)) {
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

        $tls += $this->tlsDefaults;
        $tls = array_filter($tls, function ($value) { return isset($value); });

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
     * Retrieve this host's TLS connection context options.
     *
     * @return array An array of stream encryption context options
     */
    public function getTlsContextArr(): array {
        return $this->tlsContextArr;
    }

    /**
     * Returns the host name.
     *
     * @return string
     */
    public function __toString(): string {
        return $this->name;
    }

    /**
     * Simplify debug output.
     *
     * @return array
     */
    public function __debugInfo(): array {
        $appType = is_object($this->responder)
            ? get_class($this->responder)
            : gettype($this->responder);

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
            $handlers[$class] = array_map(function (Monitor $monitor) { return $monitor->monitor(); }, $monitors);
        }
        return [
            "interfaces" => $this->interfaces,
            "name" => $this->name,
            "tls" => $this->tlsContextArr,
            "handlers" => $handlers,
        ];
    }
}

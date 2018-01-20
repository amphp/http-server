<?php

namespace Aerys\Internal;

use Amp\Socket\Certificate;
use Amp\Socket\ServerTlsContext;

class Host {
    /** @var mixed[][] */
    private $interfaces = [];

    /** @var int[][] */
    private $addressMap = [];

    /** @var ServerTlsContext|null */
    private $tlsContext;

    /**
     * Assign the IP or unix domain socket and port on which to listen.
     *
     * The address may be any valid IPv4 or IPv6 address or unix domain socket path. The "0.0.0.0"
     * indicates "all IPv4 interfaces" and is appropriate for most users. Use "::" to indicate "all
     * IPv6 interfaces". Use a "*" wildcard character to indicate "all IPv4 *and* IPv6 interfaces".
     *
     * Note that "::" may also listen on some systems on IPv4 interfaces. PHP did not expose the
     * IPV6_V6ONLY constant before PHP 7.0.1.
     *
     * Any valid port number [1-65535] may be used. Port numbers lower than 256 are reserved for
     * well-known services (like HTTP on port 80) and port numbers less than 1024 require root
     * access on UNIX-like systems. The default port for encrypted sockets (https) is 443. If you
     * plan to use encryption with this host you'll generally want to use port 443.
     *
     * @param string $address The IPv4 or IPv6 interface or unix domain socket path to listen to.
     * @param int $port The port number on which to listen (0 for unix domain sockets).
     */
    public function expose(string $address, int $port = 0) {
        $isUnixSocket = $address[0] === "/";

        if ($isUnixSocket) {
            if ($port !== 0) {
                throw new \Error(
                    "Invalid port number {$port}; must be zero for an unix domain socket"
                );
            }
        } elseif ($port < 1 || $port > 65535) {
            throw new \Error(
                "Invalid port number {$port}; integer in the range 1..65535 required"
            );
        }

        if ($address === "*") {
            if (self::separateIPv4Binding()) {
                $this->expose("0.0.0.0", $port);
            }

            $address = "::";
        }

        if (!$isUnixSocket && !@\inet_pton($address)) {
            throw new \Error(
                "Invalid IP address or unix domain socket path: {$address}"
            );
        }

        if ($isUnixSocket) {
            if ($socketPath = \realpath($address)) {
                $address = $socketPath;
            }

            $dir = \realpath(\dirname($address));
            if (!$dir || !\is_dir($dir)) {
                throw new \Error(
                    "Unix domain socket path is not in an existing or reachable directory: " . \dirname($address)
                );
            }

            if (!\is_readable($dir) || !\is_writable($dir) || !\is_executable($dir)) {
                throw new \Error(
                    "Unix domain socket path is in a directory without read, write and execute permissions: {$dir}"
                );
            }

            $packedAddress = $address = $dir. "/" .basename($address);
        } elseif (false !== $packedAddress = @\inet_pton($address)) {
            $address = \inet_ntop($packedAddress);
        } else {
            throw new \Error(
                "IPv4 or IPv6 address or unix domain socket path required: {$address} given"
            );
        }

        if (isset($this->addressMap[$packedAddress]) && \in_array($port, $this->addressMap[$packedAddress])) {
            throw new \Error(
                "There must be no two identical interfaces for a same host: {$address} duplicated"
            );
        }

        $this->interfaces[] = [$address, $port];
        $this->addressMap[$packedAddress][] = $port;
    }

    /**
     * Define TLS encryption settings for this host.
     *
     * @param string|Certificate|ServerTlsContext $certificate A string path pointing to your SSL/TLS certificate, a
     *     Certificate object, or a ServerTlsContext object
     * @param string|null $keyFile Key file with the corresponding private key or `null` if the key is in $certFile.
     *     Ignored if the first parameter is an instance of Certificate or ServerTlsContext.
     */
    public function encrypt($certificate, $keyFile = null) {
        if (!$certificate instanceof ServerTlsContext) {
            if (!$certificate instanceof Certificate) {
                $certificate = new Certificate($certificate, $keyFile);
            }

            $certificate = (new ServerTlsContext)->withDefaultCertificate($certificate);
        }

        $this->tlsContext = $certificate;
    }

    public static function separateIPv4Binding(): bool {
        static $separateIPv6 = null;

        if ($separateIPv6 === null) {
            // PHP 7.0.0 doesn't have ipv6_v6only socket option yet
            if (PHP_VERSION_ID < 70001) {
                $separateIPv6 = !file_exists("/proc/sys/net/ipv6/bindv6only") || trim(file_get_contents("/proc/sys/net/ipv6/bindv6only"));
            } else {
                $separateIPv6 = true;
            }
        }

        return $separateIPv6;
    }

    /**
     * Retrieve the URIs on which this host should be bound.
     *
     * @return array
     */
    public function getAddresses(): array {
        if (empty($this->interfaces)) {
            throw new \Error(\sprintf(
                "At least one interface must be specified (see %s::expose()), an empty interfaces array is not allowed",
                self::class
            ));
        }

        return array_map(static function ($interface) {
            list($address, $port) = $interface;

            if ($address[0] === "/") { // unix domain socket
                return "unix://$address";
            }

            if (strpos($address, ":") !== false) {
                $address = "[" . \trim($address, "[]") . "]";
            }

            return "tcp://$address:$port";
        }, $this->interfaces);
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
            $ports = [[]];
            foreach ($this->addressMap as $packedAddress => $portList) {
                if (\strlen($packedAddress) === ($address === '0.0.0.0' ? 4 : 16)) {
                    $ports[] = $portList;
                }
            }

            return \array_merge($ports, ...$ports);
        }

        // if this yields a warning, there's something else buggy, but no @ missing.
        $packedAddress = inet_pton($address);
        $wildcard = \strlen($packedAddress) === 4 ? "\0\0\0\0" : "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";

        if (!isset($this->addressMap[$wildcard])) {
            return $this->addressMap[$packedAddress] ?? [];
        }

        if (!isset($this->addressMap[$packedAddress])) {
            return $this->addressMap[$wildcard];
        }

        return array_merge($this->addressMap[$packedAddress], $this->addressMap[$wildcard]);
    }

    /**
     * Retrieve stream encryption settings array.
     *
     * @return array
     */
    public function getTlsContext(): array {
        if ($this->tlsContext === null) {
            return [];
        }

        $context = $this->tlsContext->toStreamContextArray()["ssl"];

        if (self::hasAlpnSupport()) {
            $context["alpn_protocols"] = "h2";
        }

        return $context;
    }

    /**
     * Has this host been assigned a TLS encryption context?
     *
     * @return bool Returns true if a TLS context is assigned, false otherwise
     */
    public function isEncrypted(): bool {
        return (bool) $this->tlsContext;
    }

    /**
     * @see https://wiki.openssl.org/index.php/Manual:OPENSSL_VERSION_NUMBER(3)
     * @return bool
     */
    private static function hasAlpnSupport(): bool {
        if (!\defined("OPENSSL_VERSION_NUMBER")) {
            return false;
        }

        return \OPENSSL_VERSION_NUMBER >= 0x10002000;
    }
}

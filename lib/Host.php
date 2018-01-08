<?php

namespace Aerys;

use Amp\Socket\Certificate;
use Amp\Socket\ServerTlsContext;

class Host {
    private $name = "*";

    private $interfaces = [];
    private $actions = [];

    /** @var \Aerys\Internal\HttpDriver */
    private $httpDriver;

    private $addressMap = [];

    /** @var \Amp\Socket\ServerTlsContext|null */
    private $tlsContext;
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

    /**
     * @param \Aerys\Internal\HttpDriver|null $httpDriver Internal parameter used for testing.
     */
    public function __construct(Internal\HttpDriver $httpDriver = null) {
        $this->httpDriver = $httpDriver ?? new Internal\Http1Driver;

        if (self::hasAlpnSupport()) {
            $this->tlsDefaults["alpn_protocols"] = "h2";
        }
    }

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
     * @param string $address The IPv4 or IPv6 interface or unix domain socket path to listen to
     * @param int $port The port number on which to listen (0 for unix domain sockets)
     * @return self
     */
    public function expose(string $address, int $port = 0): self {
        $isUnixSocket = $address[0] == "/";

        if ($isUnixSocket) {
            if ($port != 0) {
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

        if (!$isUnixSocket && !@inet_pton($address)) {
            throw new \Error(
                "Invalid IP address or unix domain socket path: {$address}"
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
                "IPv4 or IPv6 address or unix domain socket path required: {$address} given"
            );
        }

        if (isset($this->addressMap[$packedAddress]) && in_array($port, $this->addressMap[$packedAddress])) {
            throw new \Error(
                "There must be no two identical interfaces for a same host: {$address} duplicated"
            );
        }

        $this->interfaces[] = [$address, $port];
        $this->addressMap[$packedAddress][] = $port;

        return $this;
    }

    /**
     * Assign a domain name (e.g. localhost or mysite.com or subdomain.mysite.com).
     *
     * An explicit host name is only required if a server exposes more than one host on a given
     * interface. If a name is not defined (or "*") the server will allow any hostname.
     *
     * By default the port must match with the interface. It is possible to explicitly require
     * a specific port in the hostname by appending ":port" (e.g. "localhost:8080"). It is also
     * possible to specify a wildcard with "*" (e.g. "*:*" to accept any hostname from any port).
     *
     * @param string $name
     * @return self
     */
    public function name(string $name): self {
        $this->name = $name === "" ? "*" : $name;

        return $this;
    }

    /**
     * Use a callable request action or Filter.
     *
     * Host actions are invoked to service requests in the order in which they are added.
     *
     * @param callable|Responder|Middleware|Bootable $action
     *
     * @throws \Error on invalid $action parameter
     * @return self
     */
    public function use($action): self {
        $isAction = \is_callable($action)
            || $action instanceof Responder
            || $action instanceof Middleware
            || $action instanceof Bootable;

        if (!$isAction) {
            throw new \Error(
                \sprintf(
                    "%s() requires a callable action or an instance of %s, %s, or %s",
                    __METHOD__,
                    Responder::class,
                    Bootable::class,
                    Middleware::class
                )
            );
        }

        $this->actions[] = $action;

        return $this;
    }

    /**
     * Returns an instance of \Aerys\Responder built from the responders and middleware used on this host.
     *
     * @param callable $bootLoader
     *
     * @return \Aerys\Responder
     */
    public function buildResponder(callable $bootLoader): Responder {
        $responders = [];
        $middlewares = [];

        foreach ($this->actions as $key => $action) {
            if ($action instanceof Bootable) {
                $action = $bootLoader($action);
            }

            if ($action instanceof Middleware) {
                $middlewares[] = $action;
            }

            if (\is_callable($action)) {
                $action = new CallableResponder($action);
            }

            if ($action instanceof Responder) {
                $responders[] = $action;
            }
        }

        if (empty($responders)) {
            $responder = new CallableResponder(static function (): Response {
                return new Response\HtmlResponse("<html><body><h1>It works!</h1></body>");
            });
        } elseif (\count($responders) === 1) {
            $responder = $responders[0];
        } else {
            $responder = new TryResponder;
            foreach ($responders as $action) {
                $responder->addResponder($action);
            }
        }

        return Internal\makeMiddlewareResponder($responder, $middlewares);
    }

    /**
     * @internal
     *
     * @return \Aerys\Internal\HttpDriver
     */
    public function getHttpDriver(): Internal\HttpDriver {
        return $this->httpDriver;
    }

    /**
     * Define TLS encryption settings for this host.
     *
     * @param string|Certificate|ServerTlsContext $certificate A string path pointing to your SSL/TLS certificate, a
     *     Certificate object, or a ServerTlsContext object
     * @return self
     */
    public function encrypt($certificate): self {
        if (!$certificate instanceof ServerTlsContext) {
            if (!$certificate instanceof Certificate) {
                $certificate = new Certificate($certificate);
            }
            $certificate = (new ServerTlsContext)->withDefaultCertificate($certificate);
        }

        $this->setCrypto($certificate);

        return $this;
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
    public function getBindableAddresses(): array {
        if (empty($this->interfaces)) {
            throw new \Error(\sprintf(
                "At least one interface must be specified (see %s::expose()), an empty interfaces array is not allowed",
                self::class
            ));
        }

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
     * Retrieve stream encryption settings by bind address.
     *
     * @return array
     */
    public function getTlsBindingsByAddress(): array {
        if (!$this->isEncrypted()) {
            return [];
        }

        $bindMap = [];
        $sniNameMap = [];

        foreach ($this->getBindableAddresses() as $bindAddress) {
            $contextArr = $this->getTlsContextArr();
            $bindMap[$bindAddress] = $contextArr;

            if ($this->hasName()) {
                $sniNameMap[$bindAddress][$this->getName()] = $contextArr["local_cert"];
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
    private function setCrypto(ServerTlsContext $tls) {
        $this->tlsContext = $tls;

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
     * Does this host have a name?
     *
     * @return bool
     */
    public function hasName(): bool {
        return $this->name !== "*" && strstr($this->name, ":", true) !== "*";
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
     * Returns the host name.
     *
     * @return string
     */
    public function __toString(): string {
        return $this->name;
    }
}

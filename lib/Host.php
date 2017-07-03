<?php

namespace Aerys;

class Host {
    private static $definitions = [];
    private $name = "";
    private $interfaces = null;
    private $crypto = [];
    private $actions = [];
    private $redirect;
    private $httpDriver;

    public function __construct() {
        self::$definitions[] = $this;
    }

    public function __clone() {
        self::$definitions[] = $this;
    }

    /**
     * Assign the IP and port on which to listen.
     *
     * The address may be any valid IPv4 or IPv6 address. The "0.0.0.0" indicates
     * "all IPv4 interfaces" and is appropriate for most users. Use "::" to indicate "all IPv6
     * interfaces". To indicate "all IPv4 *and* IPv6 interfaces", use a "*" wildcard character.
     *
     * Note that "::" may also listen on some systems on IPv4 interfaces. PHP currently does
     * not expose the IPV6_V6ONLY constant.
     *
     * Any valid port number [1-65535] may be used. Port numbers lower than 256 are reserved for
     * well-known services (like HTTP on port 80) and port numbers less than 1024 require root
     * access on UNIX-like systems. The default port for encrypted sockets (https) is 443. If you
     * plan to use encryption with this host you'll generally want to use port 443.
     *
     * @param string $address The IPv4 or IPv6 interface to listen to
     * @param int $port The port number on which to listen
     * @return self
     */
    public function expose(string $address, int $port): Host {
        if ($port < 1 || $port > 65535) {
            throw new \Error(
                "Invalid port number; integer in the range 1..65535 required"
            );
        }

        if ($address === "*") {
            if (self::separateIPv4Binding()) {
                $this->interfaces[] = ["0.0.0.0", $port];
            }

            $address = "::";
        }

        if (!@inet_pton($address)) {
            throw new \Error(
                "Invalid IP address"
            );
        }

        $this->interfaces[] = [$address, $port];

        return $this;
    }

    /**
     * Assign a domain name (e.g. localhost or mysite.com or subdomain.mysite.com).
     *
     * A host name is only required if a server exposes more than one host. If a name is not defined
     * the server will default to "localhost"
     *
     * @param string $name
     * @return self
     */
    public function name(string $name): Host {
        $this->name = $name;

        return $this;
    }

    /**
     * Use a callable request action or Middleware.
     *
     * Host actions are invoked to service requests in the order in which they are added.
     *
     * @param callable|Middleware|Bootable|Monitor $action
     * @throws \Error on invalid $action parameter
     * @return self
     */
    public function use($action): Host {
        $isAction = is_callable($action) || $action instanceof Middleware || $action instanceof Bootable || $action instanceof Monitor;
        $isDriver = $action instanceof HttpDriver;

        if (!$isAction && !$isDriver) {
            throw new \Error(
                __METHOD__ . " requires a callable action or Bootable or Middleware or HttpDriver instance"
            );
        }

        if ($isAction) {
            $this->actions[] = $action;
        }
        if ($isDriver) {
            if ($this->httpDriver) {
                throw new \Error(
                    "Impossible to define two HttpDriver instances for one same Host; an instance of " . get_class($this->httpDriver) . " has already been defined as driver"
                );
            }
            $this->httpDriver = $action;
        }

        return $this;
    }

    /**
     * Define TLS encryption settings for this host.
     *
     * @param string $certificate A string path pointing to your SSL/TLS certificate
     * @param string|null $key A string path pointing to your SSL/TLS key file (null if the certificate file is containing the key already)
     * @param array $options An optional array mapping additional SSL/TLS settings
     * @return self
     */
    public function encrypt(string $certificate, string $key = null, array $options = []): Host {
        unset($options["SNI_server_certs"]);
        $options["local_cert"] = $certificate;
        if (isset($key)) {
            $options["local_pk"] = $key;
        }
        $this->crypto = $options;

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
     * Retrieve an associative array summarizing the host definition.
     *
     * @return array
     */
    public function export(): array {
        $actions = $this->actions;
        if ($this->redirect) {
            $actions[] = $this->redirect;
        }

        $defaultPort = $this->crypto ? 443 : 80;

        if (isset($this->interfaces)) {
            $interfaces = array_unique($this->interfaces, SORT_REGULAR);
        } else {
            $interfaces = [["::", $defaultPort]];
            if (self::separateIPv4Binding()) {
                $interfaces[] = ["0.0.0.0", $defaultPort];
            }
        }

        return [
            "interfaces" => $interfaces,
            "name"       => $this->name,
            "crypto"     => $this->crypto,
            "actions"    => $actions,
            "httpdriver" => $this->httpDriver,
        ];
    }

    /**
     * Used by the server bootstrapper to access host configs created by the application.
     *
     * @return array
     */
    public static function getDefinitions(): array {
        return self::$definitions;
    }
}

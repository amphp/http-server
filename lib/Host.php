<?php

namespace Aerys;

use Amp\Socket\Certificate;
use Amp\Socket\ServerTlsContext;

class Host {
    private $name = "*";
    private $interfaces = null;
    private $crypto = [];
    private $actions = [];

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
        $isPath = $address[0] == "/";

        if ($isPath) {
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
                $this->interfaces[] = ["0.0.0.0", $port];
            }

            $address = "::";
        }

        if (!$isPath && !@inet_pton($address)) {
            throw new \Error(
                "Invalid IP address or unix domain socket path"
            );
        }

        $this->interfaces[] = [$address, $port];

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
     * @param callable|Responder|Delegate|Middleware|Bootable|Monitor $action
     *
     * @throws \Error on invalid $action parameter
     * @return self
     */
    public function use($action): self {
        $isAction = \is_callable($action)
            || $action instanceof Delegate
            || $action instanceof Responder
            || $action instanceof Middleware
            || $action instanceof Bootable
            || $action instanceof Monitor;

        if (!$isAction) {
            throw new \Error(
                \sprintf(
                    "%s() requires a callable action or an instance of %s, %s, %s, %s, or %s",
                    __METHOD__,
                    Delegate::class,
                    Responder::class,
                    Bootable::class,
                    Monitor::class,
                    Middleware::class
                )
            );
        }

        $this->actions[] = $action;

        return $this;
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
        $this->crypto = $certificate;

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
            "actions"    => $this->actions,
        ];
    }
}

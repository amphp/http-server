<?php

namespace Aerys;

class Host {
    private static $definitions = [];
    private $name = "localhost";
    private $port = 80;
    private $address = "*";
    private $crypto = [];
    private $actions = [];
    private $redirect;

    public function __construct() {
        self::$definitions[] = $this;
    }

    public function __clone() {
        self::$definitions[] = $this;
    }

    /**
     * Assign the IP and port on which to listen
     *
     * The address may be any valid IPv4 or IPv6 address. The "*" wildcard character indicates
     * "all IPv4 interfaces" and is appropriate for most users. Use "[::]" to indicate "all IPv6
     * interfaces."
     *
     * Any valid port number [1-65535] may be used. Port numbers lower than 256 are reserved for
     * well-known services (like HTTP on port 80) and port numbers less than 1024 require root
     * access on UNIX-like systems. Port 80 is assumed in the absence of port specification. The
     * default port for encrypted sockets (https) is 443. If you plan to use encryption with this
     * host you'll generally want to use port 443.
     *
     * @param int $port The port number on which to listen
     * @return self
     * @TODO Make "*" listen on all IPv6 interfaces as well as IPv4
     */
    public function expose(string $address = "*", int $port = 80): Host {
        if ($address !== "*" && !@inet_pton($address)) {
            throw new \DomainException(
                "Invalid IP address"
            );
        }
        if ($port < 1 || $port > 65535) {
            throw new \DomainException(
                "Invalid port number; integer in the range 1..65535 required"
            );
        }

        $this->address = $address;
        $this->port = $port;

        return $this;
    }

    /**
     * Assign a domain name (e.g. localhost or mysite.com or subdomain.mysite.com)
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
     * Use a callable request action or Middleware
     *
     * Host actions are invoked to service requests in the order in which they are added.
     *
     * @param callable|\Aerys\Middleware $action
     * @throws \InvalidArgumentException on invalid $action parameter
     * @return self
     */
    public function use($action): Host {
        if (!(is_callable($action) || $action instanceof Middleware || $action instanceof Bootable)) {
            throw new \InvalidArgumentException(
                __METHOD__ . " requires a callable action or Middleware instance"
            );
        }

        $this->actions[] = $action;

        return $this;
    }

    /**
     * Define TLS encryption settings for this host
     *
     * @param string $certificate A string path pointing to your SSL/TLS certificate
     * @param array $options An optional array mapping additional SSL/TLS settings
     * @return self
     */
    public function encrypt(string $certificate, array $options = []): Host {
        unset($options["SNI_server_certs"]);
        $options["local_cert"] = $certificate;
        $this->crypto = $options;

        return $this;
    }

    /**
     * Redirect all requests that aren't serviced by an action callable
     *
     * NOTE: the redirect URI must match the format "scheme://hostname.tld" (with optional port).
     *
     * The following example redirects all unencrypted requests to the equivalent
     * encrypted resource:
     *
     *      <?php
     *      // Redirect http://mysite.com to https://mysite.com
     *      $host = new Aerys\Host;
     *      $host->setName("mysite.com");
     *      $host->redirect("https://mysite.com");
     *
     * @param string $absoluteUri The location to which we wish to redirect
     * @param int $redirectCode The HTTP redirect status code (300-399)
     * @return self
     */
    public function redirect(string $absoluteUri, int $redirectCode = 307): Host {
        if (!$url = @parse_url(strtolower($absoluteUri))) {
            throw new \DomainException(
                "Invalid redirect URI"
            );
        }
        if (empty($url["scheme"]) || ($url["scheme"] !== "http" && $url["scheme"] !== "https")) {
            throw new \DomainException(
                "Invalid redirect URI; \"http\" or \"https\" scheme required"
            );
        }
        if (isset($url["path"]) && $url["path"] !== "/") {
            throw new \DomainException(
                "Invalid redirect URI; Host redirect must not contain a path component"
            );
        }

        $port = empty($url["port"]) ? "" : ":{$url['port']}";
        $redirectUri = sprintf("%s://%s%s", $url["scheme"], $url["host"], $port);

        if ($redirectCode < 300 || $redirectCode > 399) {
            throw new \DomainException(
                "Invalid redirect code; code in the range 300..399 required"
            );
        }

        $this->redirect = function(Request $req, Response $res) use ($redirectUri, $redirectCode) {
            $res->setStatus($redirectCode);
            $res->setHeader("Location", $redirectUri . $req->uri);
            $res->end();
        };

        return $this;
    }

    /**
     * Retrieve an associative array summarizing the host definition
     *
     * @return array
     */
    public function export(): array {
        $actions = $this->actions;
        if ($this->redirect) {
            $actions[] = $this->redirect;
        }

        return [
            "address"   => $this->address,
            "port"      => $this->port,
            "name"      => $this->name,
            "crypto"    => $this->crypto,
            "actions"   => $actions,
        ];
    }

    /**
     * Used by the server bootstrapper to access host configs created by the application
     *
     * @return array
     */
    public static function getDefinitions(): array {
        return self::$definitions;
    }
}

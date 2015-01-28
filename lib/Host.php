<?php

namespace Aerys;

class Host {
    const PORT       = 'port';
    const ADDRESS    = 'address';
    const NAME       = 'name';
    const CRYPTO     = 'crypto';
    const ROUTES     = 'routes';
    const ROOT       = 'root';
    const WEBSOCKETS = 'websockets';
    const RESPONDERS = 'responders';

    private static $definitions = [];

    private $name = '';
    private $port = null;
    private $address = '*';
    private $httpRoutes = [];
    private $websocketRoutes = [];
    private $responders = [];
    private $crypto = [];
    private $root = [];

    public function __construct() {
        self::$definitions[] = $this;
    }

    public function __clone() {
        self::$definitions[] = $this;
    }

    /**
     * Optionally define the host's domain name (e.g. localhost or mysite.com or subdomain.mysite.com)
     *
     * A host name is only required if a server exposes more than one host. If not defined the
     * server will fallback to "localhost" for the host's name.
     *
     * @param string $name
     * @throws \InvalidArgumentException if a non-string is passed
     * @return self
     */
    public function setName($name) {
        if (!is_string($name)) {
            throw new \InvalidArgumentException(
                sprintf("%s requires a string at Argument 1", __METHOD__)
            );
        }

        $this->name = $name;

        return $this;
    }

    /**
     * Define the host's port, IP and domain name
     *
     * Any valid port number [1-65535] may be used. Port numbers lower than 256 are reserved for
     * well-known services (like HTTP on port 80) and port numbers less than 1024 require root
     * access on UNIX-like systems. Port 80 is assumed in the absence of port specification. The
     * default port for encrypted sockets (https) is 443. If you plan to use encryption with this
     * host you'll generally want to use port 443.
     *
     * @param int $port The port number on which to listen
     * @param string $interface The IP address on which to bind this host
     * @param string $name The host's domain name
     * @return self
     */
    public function setPort($port) {
        $this->port = $port;

        return $this;
    }

    /**
     * Define the IP interface on which the host will listen for requests
     *
     * The default wildcard IP value "*" translates to "all IPv4 interfaces" and is appropriate for
     * most scenarios. Valid values also include any IPv4 or IPv6 address. The string "[::]" denotes
     * an IPv6 wildcard.
     *
     * @param string $address The interface address (IP) on which the host is exposed
     * @return self
     */
    public function setAddress($address) {
        $this->address = $address;

        return $this;
    }

    /**
     * Define TLS encryption settings for this host
     *
     * An example $tlsOptions array takes the following form:
     *
     * $tlsOptions = [
     *     'passphrase'             => null,
     *     'verify_peer'            => false,
     *     'allow_self_signed'      => true,
     *     'cafile'                 => null,
     *     'capath'                 => null,
     *     'ciphers'                => '<cipher list here>',
     *     'disable_compression'    => true,
     *     'crypto_method'          => STREAM_CRYPTO_METHOD_TLS_SERVER,
     * ];
     *
     * NOTE: If specified, the local_cert array key will be overwritten using the required
     *       $certificate parameter value.
     *
     * NOTE: If specified, the "SNI_server_certs" key will be ignored. Aerys servers will
     *       automatically generate this value based on the virtual host names specified in the
     *       server config file.
     *
     * NOTE: The server will attempt to validate your certificate at boot time to ensure your
     *       settings are indeed correct. If neither the CN nor SAN names from the certificate
     *       match the host's name an error is generated but the server will still boot succesfully.
     *
     * @param string $certificate A string path pointing to your SSL/TLS certificate
     * @param array $tlsOptions An optional array mapping additional SSL/TLS settings
     * @return self
     */
    public function setCrypto($certificate, array $tlsOptions = []) {
        if (!is_string($certificate)) {
            throw new \InvalidArgumentException(
                'Invalid $certificate parameter; string expected (got '.gettype($certificate).')'
            );
        }

        unset($tlsOptions['SNI_server_certs']);
        $tlsOptions['local_cert'] = $certificate;
        $this->crypto = $tlsOptions;

        return $this;
    }

    /**
     * Specify a filesystem directory from which to serve static files
     *
     * The $options array takes the form:
     *
     *  $options = [
     *      'indexes'                   => ['index.html', 'index.htm'],
     *      'etagMode'                  => 'all',
     *      'expiresPeriod'             => 3600,
     *      'mimeFile'                  => 'etc/mime',
     *      'mimeTypes'                 => [],
     *      'defaultMimeType'           => 'text/plain',
     *      'defaultCharset'            => 'utf-8',
     *      'cacheTtl'                  => 10,
     *      'cacheMaxBuffers'           => 50,
     *      'cacheMaxBufferSize'        => 500000,
     *  ];
     *
     * Note: websocket endpoint and dynamic HTTP route URIs always take precedence over filesystem
     * resources in the event of a routing conflict.
     *
     * @param string $rootDirectory
     * @param array $options An array specifying key-value options for static file serving
     * @return self
     */
    public function setRoot($rootDirectory, array $options = []) {
        $options['root'] = $rootDirectory;
        $this->root = $options;

        return $this;
    }

    /**
     * Bind a non-blocking route handler for the specified HTTP method and URI path
     *
     * @param string $httpMethod The method for which this route applies
     * @param string $uriPath The route's URI path
     * @param mixed $handler Any callable or class::method construction string
     * @return self
     */
    public function addRoute($httpMethod, $uriPath, $handler) {
        $uriPath = '/' . ltrim($uriPath, '/');
        $this->httpRoutes[] = [$httpMethod, $uriPath, $handler];

        return $this;
    }

    /**
     * Bind a websocket endpoint to the specified URI route
     *
     * Websocket routes are slightly different from other routes because they don't require an
     * HTTP method (GET is mandated by the protocol). Websocket routes require two arguments:
     *
     * - The websocket endpoint's URI path (routing regex allowed like all other routes)
     * - The name of the websocket endpoint class
     *
     * The third argument is an optional array specifying configuration values for this websocket
     * endpoint.
     *
     * @param string $uriPath The URI path on which to bind the endpoint
     * @param mixed $websocketClassOrObj A websocket class name or Aerys\Websocket instance
     * @param array $options An array specifying key-value options for this websocket endpoint
     * @return self
     */
    public function addWebsocket($uriPath, $websocketClassOrObj, array $options = []) {
        $uriPath = '/' . ltrim($uriPath, '/');
        $this->websocketRoutes[] = [$uriPath, $websocketClassOrObj, $options];

        return $this;
    }

    /**
     * Add a user responder to the request-response chain
     *
     * User responders are always invoked in the order in which they are added to the Host.
     *
     * @param mixed $responder Any callable or class::method construction string
     * @return self
     */
    public function addResponder($responder) {
        $this->responders[] = $responder;

        return $this;
    }

    /**
     * Retrieve an associative array summarizing the host definition
     *
     * @return array
     */
    public function toArray() {
        return [
            self::PORT          => $this->port ?: ($this->crypto ? 443 : 80),
            self::ADDRESS       => $this->address,
            self::NAME          => $this->name,
            self::CRYPTO        => $this->crypto,
            self::ROOT          => $this->root,
            self::ROUTES        => $this->httpRoutes,
            self::WEBSOCKETS    => $this->websocketRoutes,
            self::RESPONDERS    => $this->responders,
        ];
    }

    /**
     * Unregister this host so that the server will not use it at boot time
     *
     * This method is useful if you wish to create a "template" Host to use as a base to then clone
     * it as a baseline for multiple other hosts. Any Host instances calling this method will be
     * ignored by the server at boot time.
     *
     * Example:
     *
     *     <?php
     *     $template = (new Aerys\Host)->unregister();
     *     $template->setCrypto('/path/to/san/cert.pem');
     *
     *     $mysite = (clone $template)->setName('mysite.com')->addResponder(...);
     *     $files  = (clone $template)->setName('static.mysite.com')->setRoot(...);
     *
     *
     * @return self
     */
    public function unregister() {
        $key = array_search($this, self::$definitions, true);
        if ($key !== false) {
            unset(self::$definitions[$key]);
            self::$definitions = array_values(self::$definitions);
        }

        return $this;
    }

    /**
     * Used by the server bootstrapper to access host configs created by the application
     *
     * @return array
     */
    public static function getDefinitions() {
        return self::$definitions;
    }
}

<?php

namespace Aerys;

use Aerys\Cookie\Cookie;
use Psr\Log\LoggerInterface as PsrLogger;

/**
 * Create a router for use in a Host instance.
 *
 * @param array $options Router options
 * @return \Aerys\Router Returns a Bootable Router instance
 */
function router(array $options = []): Router {
    $router = new Router;
    foreach ($options as $key => $value) {
        $router->setOption($key, $value);
    }

    return $router;
}

/**
 * Create a Websocket application for use in a Host instance.
 *
 * @param \Aerys\Websocket|\Aerys\Bootable $app The websocket app to use
 * @param array $options Endpoint options
 * @return \Aerys\Bootable Returns a Bootable to manufacture an Aerys\Websocket\Endpoint
 */
function websocket($app, array $options = []): Bootable {
    return new class($app, $options) implements Bootable {
        private $app;
        private $options;
        public function __construct($app, array $options) {
            $this->app = $app;
            $this->options = $options;
        }
        public function boot(Server $server, PsrLogger $logger): callable {
            $app = ($this->app instanceof Bootable)
                ? $this->app->boot($server, $logger)
                : $this->app;
            if (!$app instanceof Websocket) {
                $type = \is_object($app) ? \get_class($app) : \gettype($app);
                throw new \TypeError(
                    \sprintf("Cannot boot websocket handler; %s required, %s provided", Websocket::class, $type)
                );
            }
            $gateway = new Websocket\Rfc6455Gateway($logger, $app);
            foreach ($this->options as $key => $value) {
                $gateway->setOption($key, $value);
            }
            $this->app = null;
            $this->options = null;

            $server->attach($gateway);

            return $gateway;
        }
    };
}

/**
 * Create a static file root for use in a Host instance.
 *
 * @param string $docroot The filesystem directory from which to serve documents
 * @param array $options Static file serving options
 * @return \Aerys\Bootable Returns a Bootable to manufacture an Aerys\Root
 */
function root(string $docroot, array $options = []): Bootable {
    return new class($docroot, $options) implements Bootable {
        private $docroot;
        private $options;
        public function __construct(string $docroot, array $options) {
            $this->docroot = $docroot;
            $this->options = $options;
        }
        public function boot(Server $server, PsrLogger $logger): callable {
            $root = new Root($this->docroot);
            $options = $this->options;
            $defaultMimeFile = __DIR__ ."/../etc/mime";
            if (!array_key_exists("mimeFile", $options) && file_exists($defaultMimeFile)) {
                $options["mimeFile"] = $defaultMimeFile;
            }
            foreach ($options as $key => $value) {
                $root->setOption($key, $value);
            }

            $server->attach($root);

            return $root;
        }
    };
}

/**
 * Create a redirect handler callable for use in a Host instance.
 *
 * @param string $absoluteUri Absolute URI prefix to redirect to
 * @param int $redirectCode HTTP status code to set
 * @return callable Responder callable
 */
function redirect(string $absoluteUri, int $redirectCode = 307): callable {
    if (!$url = @parse_url($absoluteUri)) {
        throw new \Error("Invalid redirect URI");
    }
    if (empty($url["scheme"]) || ($url["scheme"] !== "http" && $url["scheme"] !== "https")) {
        throw new \Error("Invalid redirect URI; \"http\" or \"https\" scheme required");
    }
    if (isset($url["query"]) || isset($url["fragment"])) {
        throw new \Error("Invalid redirect URI; Host redirect must not contain a query or fragment component");
    }

    $absoluteUri = rtrim($absoluteUri, "/");

    if ($redirectCode < 300 || $redirectCode > 399) {
        throw new \Error("Invalid redirect code; code in the range 300..399 required");
    }

    return function (Request $req) use ($absoluteUri, $redirectCode) {
        $uri = $req->getUri();
        $path = $uri->getPath();
        $query = $uri->getQuery();

        if ($query) {
            $path .= "?" . $query;
        }

        return new Response\RedirectResponse($absoluteUri . $path, $redirectCode);
    };
}

/**
 * Try parsing a the Request's body with either x-www-form-urlencoded or multipart/form-data.
 *
 * @param Request $req
 * @return BodyParser (returns a ParsedBody instance when yielded)
 */
function parseBody(Request $req, $size = 0): BodyParser {
    return new BodyParser($req, [
        "input_vars" => $req->getOption("maxInputVars"),
        "field_len" => $req->getOption("maxFieldLen"),
        "size" => $size <= 0 ? $req->getOption("maxBodySize") : $size,
    ]);
}

/**
 * Parses cookies into an array.
 *
 * @param string $cookies
 *
 * @return \Aerys\Cookie\Cookie[]
 */
function parseCookie(string $cookies): array {
    $result = [];

    foreach (\explode("; ", $cookies) as $cookie) {
        $result[] = Cookie::fromHeader($cookie);
    }

    return $result;
}

/**
 * Create a generic HTML entity body.
 *
 * @param int $status
 * @param array $options
 * @return string
 */
function makeGenericBody(int $status, array $options = []): string {
    $reason = $options["reason"] ?? (HTTP_REASON[$status] ?? "");
    $subhead = isset($options["sub_heading"]) ? "<h3>{$options["sub_heading"]}</h3>" : "";
    $server = empty($options["server_token"]) ? "" : (SERVER_TOKEN . " @ ");
    $date = $options["http_date"] ?? gmdate("D, d M Y H:i:s") . " GMT";
    $msg = isset($options["message"]) ? "{$options["message"]}\n" : "";

    return sprintf(
        "<html>\n<body>\n<h1>%d %s</h1>\n%s\n<hr/>\n<em>%s%s</em>\n<br/><br/>\n%s</body>\n</html>",
        $status,
        $reason,
        $subhead,
        $server,
        $date,
        $msg
    );
}

/**
 * Initializes the server directly from a given set of Hosts.
 *
 * @param PsrLogger $logger
 * @param Host[] $hosts
 * @param array $options Aerys options array
 * @return Server
 */
function initServer(PsrLogger $logger, array $hosts, array $options = []): Server {
    foreach ($hosts as $host) {
        if (!$host instanceof Host) {
            throw new \TypeError(
                "Expected an array of Hosts as second parameter, but array also contains ".(is_object($host) ? "instance of " .get_class($host) : gettype($host))
            );
        }
    }

    if (!array_key_exists("debug", $options)) {
        $options["debug"] = false;
    }

    $options = Internal\generateOptionsObjFromArray($options);
    $vhosts = new VhostContainer(new Http1Driver);
    $ticker = new Ticker($logger);
    $server = new Server($options, $vhosts, $logger, $ticker);

    $bootLoader = static function (Bootable $bootable) use ($server, $logger) {
        $booted = $bootable->boot($server, $logger);
        if ($booted !== null && !$booted instanceof ErrorHandler && !is_callable($booted)) {
            throw new \Error("Any return value of " . get_class($bootable) . '::boot() must return an instance of Aerys\Responder and/or be callable, got ' . gettype($booted) . ".");
        }
        return $booted ?? $bootable;
    };
    foreach ($hosts ?: [new Host] as $host) {
        $vhost = Internal\buildVhost($host, $bootLoader);
        $vhosts->use($vhost);
    }

    return $server;
}

/**
 * Gives the absolute path of a config file.
 *
 * @param string $configFile path to config file used by Aerys instance
 * @return string
 */
function selectConfigFile(string $configFile): string {
    if ($configFile == "") {
        throw new \Error(
            "No config file found, specify one via the -c switch on command line"
        );
    }

    $path = realpath(is_dir($configFile) ? rtrim($configFile, "/") . "/config.php" : $configFile);

    if ($path === false) {
        throw new \Error("No config file found at " . $configFile);
    }

    return $path;
}

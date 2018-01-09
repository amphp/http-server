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
 * @param \Aerys\Websocket\Websocket|\Aerys\Bootable $app The websocket app to use
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
        public function boot(Server $server, PsrLogger $logger): Responder {
            $app = ($this->app instanceof Bootable)
                ? $this->app->boot($server, $logger)
                : $this->app;
            if (!$app instanceof Websocket\Websocket) {
                $type = \is_object($app) ? \get_class($app) : \gettype($app);
                throw new \TypeError(
                    \sprintf("Cannot boot websocket handler; %s required, %s provided", Websocket\Websocket::class, $type)
                );
            }
            $gateway = new Websocket\Internal\Rfc6455Gateway($logger, $app);
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
        public function boot(Server $server, PsrLogger $logger): Root {
            $root = new Root($this->docroot);
            $options = $this->options;
            foreach ($options as $key => $value) {
                $root->setOption($key, $value);
            }

            $server->attach($root);

            return $root;
        }
    };
}

/**
 * Create a redirect responder for use in a Host instance.
 *
 * @param string $absoluteUri Absolute URI prefix to redirect to
 * @param int $redirectCode HTTP status code to set
 * @return callable Responder callable
 */
function redirect(string $absoluteUri, int $redirectCode = 307): Responder {
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

    return new CallableResponder(function (Request $req) use ($absoluteUri, $redirectCode) {
        $uri = $req->getUri();
        $path = $uri->getPath();
        $query = $uri->getQuery();

        if ($query) {
            $path .= "?" . $query;
        }

        return new Response\RedirectResponse($absoluteUri . $path, $redirectCode);
    });
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
        $cookie = Cookie::fromHeader($cookie);
        $result[$cookie->getName()] = $cookie;
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
    $reason = $options["reason"] ?? HttpStatus::getReason($status);
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

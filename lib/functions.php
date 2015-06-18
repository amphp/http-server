<?php

namespace Aerys;

use Amp\Reactor;

/**
 * Create a router for use in a Host instance
 *
 * @param array $options Router options
 * @return \Aerys\Router Returns a Bootable Router instance
 */
function router(array $options = []) {
    $router = new Router;
    foreach ($options as $key => $value) {
        $router->setOption($key, $value);
    }

    return $router;
}

/**
 * Create a Websocket application for use in a Host instance
 *
 * @param \Aerys\Websocket|\Aerys\Bootable $app The websocket app to use
 * @param array $options Endpoint options
 * @return \Aerys\Bootable Returns a Bootable to manufacture an Aerys\Websocket\Endpoint
 */
function websocket($app, array $options = []) {
    return new class($app, $options) implements Bootable {
        private $app;
        private $options;
        public function __construct($app, array $options) {
            $this->app = $app;
            $this->options = $options;
        }
        public function boot(Reactor $reactor, Server $server, Logger $logger) {
            $app = ($this->app instanceof Bootable)
                ? $this->app->boot($reactor, $server, $logger)
                : $this->app;
            if (!$app instanceof Websocket) {
                $type = is_object($app) ? get_class($app) : gettype($app);
                throw new \DomainException(
                    "Cannot boot websocket handler; Aerys\\Websocket required, {$type} provided"
                );
            }
            $endpoint = new Websocket\Rfc6455Endpoint($reactor, $logger, $app);
            foreach ($this->options as $key => $value) {
                $endpoint->setOption($key, $value);
            }
            $this->app = null;
            $this->options = null;

            $server->attach($endpoint);

            return [$endpoint, "__invoke"];
        }
    };
}

/**
 * Create a static file root for use in a Host instance
 *
 * @param string $docroot The filesystem directory from which to serve documents
 * @param array $options Static file serving options
 * @return \Aerys\Bootable Returns a Bootable to manufacture an Aerys\Root\Root
 */
function root(string $docroot, array $options = []) {
    return new class($docroot, $options) implements Bootable {
        private $docroot;
        private $options;
        public function __construct(string $docroot, array $options) {
            $this->docroot = $docroot;
            $this->options = $options;
        }
        public function boot(Reactor $reactor, Server $server, Logger $logger) {
            $debug = $server->getOption("debug");
            $root = ($reactor instanceof \Amp\UvReactor)
                ? new Root\UvRoot($reactor, $this->docroot, $debug)
                : new Root\BlockingRoot($reactor, $this->docroot, $debug)
            ;
            $options = $this->options;
            $defaultMimeFile = __DIR__ ."/../etc/mime";
            if (!array_key_exists("mimeFile", $options) && file_exists($defaultMimeFile)) {
                $options["mimeFile"] = $defaultMimeFile;
            }
            foreach ($options as $key => $value) {
                $root->setOption($key, $value);
            }

            $server->attach($root);

            return [$root, "__invoke"];
        }
    };
}

/**
 * Parses cookies into an array
 *
 * @param string $cookies
 * @return array with name => value pairs
 */
function parseCookie(string $cookies): array {
    $arr = [];

    foreach (explode("; ", $cookies) as $cookie) {
        if (strpos($cookie, "=") !== false) { // do not trigger notices for malformed cookies...
            list($name, $val) = explode("=", $cookie, 2);
            $arr[$name] = $val;
        }
    }

    return $arr;
}

/**
 * Apply negotiated gzip deflation to outgoing response bodies
 *
 * @param \Aerys\InternalRequest $ireq
 * @return \Generator
 */
function deflateResponseFilter(InternalRequest $ireq): \Generator {
    if (empty($ireq->headers["accept-encoding"])) {
        return;
    }

    // @TODO Perform a more sophisticated check for gzip acceptance.
    // This check isn't technically correct as the gzip parameter
    // could have a q-value of zero indicating "never accept gzip."
    foreach ($ireq->headers["accept-encoding"] as $value) {
        if (stripos($value, "gzip") !== false) {
            break;
        }
        return;
    }

    $headers = yield;

    // We can't deflate if we don't know the content-type
    if (empty($headers["content-type"])) {
        return;
    }

    // Require a text/* mime Content-Type
    // @TODO Allow option to configure which mime prefixes/types may be compressed
    if (stripos($headers["content-type"][0], "text/") !== 0) {
        return;
    }

    $minBodySize = $ireq->options->deflateMinimumLength;
    $contentLength = $headers["content-length"][0] ?? null;
    $bodyBuffer = "";

    if (!isset($contentLength)) {
        // Wait until we know there's enough stream data to compress before proceeding.
        // If we receive a FLUSH or an END signal before we have enough then we won't
        // use any compression.
        while (!isset($bodyBuffer[$minBodySize])) {
            $bodyBuffer .= ($tmp = yield);
            if ($tmp === false || $tmp === null) {
                $bodyBuffer .= yield $headers;
                return $bodyBuffer;
            }
        }
    } elseif (empty($contentLength) || $contentLength < $minBodySize) {
        // If the Content-Length is too small we can't compress it.
        return $headers;
    }

    // @TODO We have the ability to support DEFLATE and RAW encoding as well. Should we?
    $mode = \ZLIB_ENCODING_GZIP;
    if (($resource = \deflate_init($mode)) === false) {
        throw new \RuntimeException(
            "Failed initializing deflate context"
        );
    }

    // Once we decide to compress output we no longer know what the
    // final Content-Length will be. We need to update our headers
    // according to the HTTP protocol in use to reflect this.
    // @TODO This may require updating for HTTP/2.0
    unset($headers["content-length"]);
    if ($ireq->protocol === "1.1") {
        $headers["transfer-encoding"] = ["chunked"];
    } else {
        $headers["connection"] = ["close"];
    }
    $headers["content-encoding"] = ["gzip"];
    $minFlushOffset = $ireq->options->deflateBufferSize;
    $deflated = $headers;

    while (($uncompressed = yield $deflated) !== null) {
        $bodyBuffer .= $uncompressed;
        if ($uncompressed === false) {
            if ($bodyBuffer === "") {
                $deflated = null;
            } elseif (($deflated = \deflate_add($resource, $bodyBuffer, \ZLIB_SYNC_FLUSH)) === false) {
                throw new \RuntimeException(
                    "Failed adding data to deflate context"
                );
            } else {
                $bodyBuffer = "";
            }
        } elseif (!isset($bodyBuffer[$minFlushOffset])) {
            $deflated = null;
        } elseif (($deflated = \deflate_add($resource, $bodyBuffer)) === false) {
            throw new \RuntimeException(
                "Failed adding data to deflate context"
            );
        } else {
            $bodyBuffer = "";
        }
    }

    if (($deflated = \deflate_add($resource, $bodyBuffer, \ZLIB_FINISH)) === false) {
        throw new \RuntimeException(
            "Failed adding data to deflate context"
        );
    }

    return $deflated;
}

/**
 * Filter out entity body data from a response stream
 *
 * @param \Aerys\InternalRequest $ireq
 * @return \Generator
 */
function nullBodyResponseFilter(InternalRequest $ireq): \Generator {
    // Receive headers and immediately send them back.
    yield yield;
    // Yield null (need more data) for all subsequent body data
    while (yield !== null);
}

/**
 * Use a generic HTML response if the requisite header is assigned
 *
 * @param \Aerys\InternalRequest $ireq
 * @return \Generator
 */
function genericResponseFilter(InternalRequest $ireq): \Generator {
    $headers = yield;
    if (empty($headers["aerys-generic-response"])) {
        return;
    }

    $body = makeGenericBody($headers[":status"], $options = [
        "reason"      => $headers[":reason"],
        "sub_heading" => "Requested: {$ireq->uri}",
        "server"      => $ireq->options->sendServerToken ?? false,
        "http_date"   => $ireq->httpDate,
    ]);
    $headers["content-length"] = [strlen($body)];
    unset(
        $headers["aerys-generic-response"],
        $headers["transfer-encoding"]
    );

    yield $headers;

    return $body;
}

/**
 * Create a generic HTML entity body
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
    $msg = isset($options["msg"]) ? "{$options["msg"]}\n" : "";

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

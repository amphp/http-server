<?php

namespace Aerys;

use Amp\{
    Promise,
    Success,
    Failure,
    function resolve
};

use FastRoute\{
    Dispatcher,
    RouteCollector,
    function simpleDispatcher
};

class Router implements ServerObserver {
    private $canonicalRedirector;
    private $routeDispatcher;
    private $routes = [];
    private $cache = [];
    private $cacheSize = 0;
    private $maxCacheSize;

    public function __construct(int $maxCacheSize = 512) {
        $this->maxCacheSize = $maxCacheSize;
        $this->canonicalRedirector = function(Request $req, Response $res) {
            $res->setStatus(HTTP_STATUS["FOUND"]);
            $res->setHeader("Location", "{$req->uri}/");
            $res->setHeader("Content-Type", "text/plain; charset=utf-8");
            $res->end("Canonical resource URI: {$req->uri}/");
        };
    }

    /**
     * Route requests to one of our registered route actions
     *
     * @param \Aerys\Request $request
     * @param \Aerys\Response $response
     * @throws \UnexpectedValueException If unknown route dispatcher code encountered
     * @return mixed
     */
    public function __invoke(Request $request, Response $response) {
        $toMatch = $request->uriPath;

        if (isset($this->dispatchCache[$toMatch])) {
            list($action, $request->locals->routeArgs) = $cache = $this->dispatchCache[$toMatch];
            // Move the entry to the back of the LRU cache
            unset($this->dispatchCache[$toMatch]);
            $this->dispatchCache[$toMatch] = $cache;

            return ($action)($request, $response);
        }

        $match = $this->routeDispatcher->dispatch($request->method, $toMatch);

        switch ($match[0]) {
            case Dispatcher::FOUND:
                $action = $match[1];
                $request->locals->routeArgs = $routeArgs = $match[2];
                if ($this->cacheSize === $this->maxCacheSize) {
                    $unsetMe = key($this->cache);
                    unset($this->cache[$unsetMe]);
                } else {
                    $this->cacheSize++;
                }
                $this->dispatchCache[$toMatch] = [$action, $routeArgs];

                return ($action)($request, $response);

            case Dispatcher::NOT_FOUND:
                // Do nothing; allow actions further down the chain a chance to respond.
                // If no other registered host actions respond the server will send a
                // 404 automatically anyway.
                return;
            case Dispatcher::METHOD_NOT_ALLOWED:
                $status = HTTP_STATUS["METHOD_NOT_ALLOWED"];
                $response->setStatus($status);
                $response->setHeader("Aerys-Generic-Response", "enable");
                $response->end();
                break;
            default:
                throw new \UnexpectedValueException(
                    "Encountered unexpected Dispatcher code"
                );
        }
    }

    /**
     * Allow shortcut route registration using the called method name as the HTTP method verb
     *
     * HTTP method verbs -- though case-sensitive -- are used in all-caps for most applications.
     * Shortcut method verbs will automatically be changed to all-caps. Applications wishing to
     * define case-sensitive methods should use Router::route() to specify the desired method
     * directly.
     *
     * @param string $method
     * @param string $uri
     * @param callable $actions
     * @return self
     */
    public function __call(string $method, array $args): Router {
        $uri = $args ? array_shift($args) : null;
        return $this->route(strtoupper($method), $uri, ...$args);
    }

    /**
     * Define an application route
     *
     * The variadic ...$actions argument allows applications to specify multiple separate
     * handlers for a given route URI. When matched these action callables will be invoked
     * in order until one starts a response. If the resulting action fails to send a response
     * the end result is a 404.
     *
     * Matched URI route arguments are made available to action callables as an array in the
     * following Request property:
     *
     *     $request->locals->routeArgs array.
     *
     * Route URIs ending in "/?" (without the quotes) allow a URI match with or without
     * the trailing slash. Temorary redirects are used to redirect to the canonical URI
     * (with a trailing slash) to avoid search engine duplicate content penalties.
     *
     * @param string $method The HTTP method verb for which this route applies
     * @param string $uri The string URI
     * @param callable $actions The action(s) to invoke upon matching this route
     * @throws \DomainException on empty $uri or $actions
     * @return self
     */
    public function route(string $method, string $uri, callable ...$actions): Router {
        if ($uri === "") {
            throw new \DomainException(
                __METHOD__ . " requires a non-empty URI string at Argument 2"
            );
        }

        $uri = "/" . ltrim($uri, "/");
        $target = $this->makeCallableTargetFromActionsArray($actions);
        if (substr($uri, -2) === "/?") {
            $canonicalUri = substr($uri, 0, -1);
            $redirectUri = substr($uri, 0, -2);
            $this->routes[] = [$method, $canonicalUri, $target];
            $this->routes[] = [$method, $redirectUri, $this->canonicalRedirector];
        } else {
            $this->routes[] = [$method, $uri, $target];
        }

        return $this;
    }

    private function makeCallableTargetFromActionsArray(array $actions): callable {
        if (empty($actions)) {
            throw new \DomainException(
                __METHOD__ . " requires at least one callable route action at Argument 3"
            );
        }

        if (empty($actions[1])) {
            return $actions[0];
        }

        return function(Request $request, Response $response) use ($actions) {
            foreach ($actions as $action) {
                $result = ($action)($request, $response);
                if ($result instanceof \Generator) {
                    yield from $result;
                }
                if ($response->state() & Response::STARTED) {
                    return;
                }
            }
        };
    }

    /**
     * React to server state changes
     *
     * Here we generate our dispatcher when the server notifies us that it is
     * ready to start (Server::STARTING). Because the Router is an instance of
     * Aerys\ServerObserver it will automatically be attached to the server as
     * an observer when passed to Aerys\Host::add().
     *
     * @param \SplSubject $subject The notifying Aerys\Server instance
     * @return \Amp\Promise
     */
    public function update(\SplSubject $subject): Promise {
        if ($subject->state() !== Server::STARTING) {
            return new Success;
        }

        if (empty($this->routes)) {
            return new Failure(new \DomainException(
                "Router start failure: no routes registered"
            ));
        }

        $this->routeDispatcher = simpleDispatcher(function(RouteCollector $routeCollector) {
            foreach ($this->routes as list($method, $uri, $action)) {
                $routeCollector->addRoute($method, $uri, $action);
            }
        });

        return new Success;
    }

}

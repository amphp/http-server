<?php

namespace Aerys;

use FastRoute\{
    Dispatcher,
    RouteCollector,
    function simpleDispatcher
};

use Amp\{
    Promise,
    Success,
    Failure,
    Reactor,
    function resolve,
    function getReactor
};

class Router implements ServerObserver {
    private $reactor;
    private $canonicalRedirector;
    private $routeDispatcher;
    private $routes = [];
    private $cache = [];
    private $cacheTimeouts = [];
    private $cacheInvalidator;
    private $cacheWatcher;
    private $cacheSize = 0;
    private $cacheTtl = 5;
    private $maxCacheSize = 1024;
    private $enableCache = true;
    private $now;

    public function __construct(Reactor $reactor = null) {
        $this->reactor = $reactor ?: getReactor();
        $this->canonicalRedirector = function(Request $req, Response $res) {
            $redirectTo = $req->uriQuery ? "{$req->uriPath}/?{$req->uriQuery}" : "{$req->uriPath}/";
            $res->setStatus(HTTP_STATUS["FOUND"]);
            $res->setHeader("Location", $redirectTo);
            $res->setHeader("Content-Type", "text/plain; charset=utf-8");
            $res->end("Canonical resource URI: {$req->uri}/");
        };
        $this->cacheInvalidator = (new \ReflectionClass($this))
            ->getMethod("invalidateCacheEntries")
            ->getClosure($this)
        ;
    }

    /**
     * Route a request
     *
     * @param \Aerys\Request $request
     * @param \Aerys\Response $response
     * @throws \UnexpectedValueException If unknown route dispatcher code encountered
     * @return mixed
     */
    public function __invoke(Request $request, Response $response) {
        $toMatch = $request->uriPath;

        if (isset($this->cache[$toMatch])) {
            list($action, $request->locals->routeArgs) = $this->cache[$toMatch];
            // Keep the most recently used entry at the back of the LRU cache
            unset($this->cacheTimeouts[$toMatch]);
            $this->cacheTimeouts[$toMatch] = $this->now + $this->cacheTtl;

            return ($action)($request, $response);
        }

        $match = $this->routeDispatcher->dispatch($request->method, $toMatch);

        switch ($match[0]) {
            case Dispatcher::FOUND:
                return $this->onRouteMatch($match, $request, $response);
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

    private function onRouteMatch(array $match, Request $request, Response $response) {
        list(, $action, $request->locals->routeArgs) = $match;
        if ($this->enableCache) {
            if ($this->cacheSize < $this->maxCacheSize) {
                $this->cacheSize++;
            } else {
                // Remove the oldest entry from the LRU cache to make room
                $unsetMe = key($this->cache);
                unset(
                    $this->cache[$unsetMe],
                    $this->cacheTimeouts[$unsetMe]
                );
            }

            $cacheKey = $request->uriPath;
            $this->cache[$cacheKey] = [$action, $request->locals->routeArgs];
            $this->cacheTimeouts[$cacheKey] = $this->now + $this->cacheTtl;
        }

        return ($action)($request, $response);
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
    public function update(\SplSubject $server): Promise {
        switch ($server->state()) {
            case Server::STOPPED:
                if (isset($this->cacheWatcher)) {
                    $this->reactor->cancel($this->cacheWatcher);
                    $this->cacheWatcher = null;
                }
                break;
            case Server::STARTED:
                if ($this->enableCache) {
                    $this->cacheWatcher = $this->reactor->repeat($this->cacheInvalidator, 1000);
                }
                break;
            case Server::STARTING:
                if (empty($this->routes)) {
                    return new Failure(new \DomainException(
                        "Router start failure: no routes registered"
                    ));
                }
                $this->routeDispatcher = simpleDispatcher(function(RouteCollector $rc) {
                    foreach ($this->routes as list($method, $uri, $action)) {
                        $rc->addRoute($method, $uri, $action);
                    }
                });
                break;
        }

        return new Success;
    }

    private function invalidateCacheEntries() {
        $this->now = $now = time();
        foreach ($this->cacheTimeouts as $cacheKey => $expiresAt) {
            if ($now < $expiresAt) {
                return;
            }
            $this->cacheSize--;
            unset(
                $this->cache[$cacheKey],
                $this->cacheTimeouts[$cacheKey]
            );
        }
    }
}

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
    function any
};

class Router implements ServerObserver {
    private $canonicalRedirector;
    private $routeDispatcher;
    private $routes = [];
    private $cache = [];
    private $cacheEntryCount = 0;
    private $maxCacheEntries = 512;
    private $serverObservers = [];

    public function __construct(array $options = []) {
        $this->setOptions($options);
        $this->canonicalRedirector = function(Request $req, Response $res) {
            $redirectTo = $req->uriQuery ? "{$req->uriPath}/?{$req->uriQuery}" : "{$req->uriPath}/";
            $res->setStatus(HTTP_STATUS["FOUND"]);
            $res->setHeader("Location", $redirectTo);
            $res->setHeader("Content-Type", "text/plain; charset=utf-8");
            $res->end("Canonical resource URI: {$req->uri}/");
        };
    }

    private function setOptions(array $options) {
        foreach ($options as $key => $value) {
            switch ($key) {
                case "max_cache_entries":
                    if (!is_int($value)) {
                        throw new \DomainException(sprintf(
                            "max_cache_entries requires an integer; %s specified",
                            is_object($value) ? get_class($value) : gettype($value)
                        ));
                    }
                    $this->maxCacheEntries = ($value < 1) ? 0 : $value;
                    break;
                default:
                    throw new \DomainException(
                        "Unknown Router option: {$key}"
                    );
            }
        }
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
            list($action, $request->locals->routeArgs) = $cache = $this->cache[$toMatch];
            // Keep the most recently used entry at the back of the LRU cache
            unset($this->cache[$toMatch]);
            $this->cache[$toMatch] = $cache;

            return $action($request, $response);
        }

        $match = $this->routeDispatcher->dispatch($request->method, $toMatch);

        switch ($match[0]) {
            case Dispatcher::FOUND:
                list(, $action, $request->locals->routeArgs) = $match;
                if ($this->maxCacheEntries > 0) {
                    $this->cacheDispatchResult($action, $request);
                }

                return $action($request, $response);
                break;
            case Dispatcher::NOT_FOUND:
                // Do nothing; allow actions further down the chain a chance to respond.
                // If no other registered host actions respond the server will send a
                // 404 automatically anyway.
                return;
                break;
            case Dispatcher::METHOD_NOT_ALLOWED:
                $response->setStatus(HTTP_STATUS["METHOD_NOT_ALLOWED"]);
                $response->setHeader("Aerys-Generic-Response", "enable");
                $response->end();
                break;
            default:
                throw new \UnexpectedValueException(
                    "Encountered unexpected Dispatcher code"
                );
        }
    }

    private function cacheDispatchResult(callable $action, Request $request) {
        if ($this->cacheEntryCount < $this->maxCacheEntries) {
            $this->cacheEntryCount++;
        } else {
            // Remove the oldest entry from the LRU cache to make room
            $unsetMe = key($this->cache);
            unset($this->cache[$unsetMe]);
        }

        $cacheKey = $request->uriPath;
        $this->cache[$cacheKey] = [$action, $request->locals->routeArgs];
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
        $uri = $args ? array_shift($args) : "";

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
     * @throws \DomainException on invalid empty parameters
     * @return self
     */
    public function route(string $method, string $uri, callable ...$actions): Router {
        if ($method === "") {
            throw new \DomainException(
                __METHOD__ . " requires a non-empty string HTTP method at Argument 1"
            );
        }
        if ($uri === "") {
            throw new \DomainException(
                __METHOD__ . " requires a non-empty string URI at Argument 2"
            );
        }
        if (empty($actions)) {
            throw new \DomainException(
                __METHOD__ . " requires at least one callable route action at Argument 3"
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
        // We need to store ServerObserver route targets so they can be notified
        // upon server state changes.
        foreach ($actions as $action) {
            if ($action instanceof ServerObserver) {
                $this->serverObservers[] = $action;
            } elseif (is_array($action) && $action[0] instanceof ServerObserver) {
                $this->serverObservers[] = $action[0];
            }
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
     * an observer when passed to Aerys\Host::use().
     *
     * @param \SplSubject $subject The notifying Aerys\Server instance
     * @return \Amp\Promise
     */
    public function update(\SplSubject $server): Promise {
        $observerPromises = [];
        foreach ($this->serverObservers as $serverObserver) {
            $observerPromises[] = $serverObserver->update($server);
        }
        switch ($server->state()) {
            case Server::STOPPED:
                $this->routeDispatcher = null;
                break;
            case Server::STARTING:
                if (empty($this->routes)) {
                    $observerPromises[] = new Failure(new \DomainException(
                        "Router start failure: no routes registered"
                    ));
                } else {
                    $this->routeDispatcher = simpleDispatcher(function(RouteCollector $rc) {
                        foreach ($this->routes as list($method, $uri, $action)) {
                            $rc->addRoute($method, $uri, $action);
                        }
                    });
                }
                break;
        }

        return any($observerPromises);
    }
}

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

use Psr\Log\{
    LoggerInterface as PsrLogger,
    LoggerAwareInterface as LoggerAware
};

class Router implements ServerObserver, LoggerAware {
    private $canonicalRedirector;
    private $routeDispatcher;
    private $routes = [];
    private $cache = [];
    private $cacheEntryCount = 0;
    private $maxCacheEntries = 512;
    private $serverObservers = [];
    private $loggerAwares = [];
    private $logger;
    private $state = Server::STOPPED;

    public function __construct(array $options = []) {
        $this->setOptions($options);
        $this->canonicalRedirector = function(Request $request, Response $response) {
            $uri = $request->getUri();
            if (stripos($uri, "?")) {
                list($path, $query) = explode("?", $uri, 2);
                $redirectTo = "{$path}/?{$query}";
            } else {
                $path = $uri;
                $redirectTo = "{$uri}/";
            }
            $response->setStatus(HTTP_STATUS["FOUND"]);
            $response->setHeader("Location", $redirectTo);
            $response->setHeader("Content-Type", "text/plain; charset=utf-8");
            $response->end("Canonical resource URI: {$path}/");
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
        $uri = $request->getUri();
        $toMatch = ($qPos = stripos($uri, "?")) ? substr($uri, 0, $qPos) : $uri;

        if (isset($this->cache[$toMatch])) {
            list($action, $routeArgs) = $cache = $this->cache[$toMatch];
            $request->setLocalVar("aerys.routeArgs", $routeArgs);
            // Keep the most recently used entry at the back of the LRU cache
            unset($this->cache[$toMatch]);
            $this->cache[$toMatch] = $cache;

            return $action($request, $response);
        }

        $match = $this->routeDispatcher->dispatch($request->getMethod(), $toMatch);

        switch ($match[0]) {
            case Dispatcher::FOUND:
                list(, $action, $routeArgs) = $match;
                $request->setLocalVar("aerys.routeArgs", $routeArgs);
                if ($this->maxCacheEntries > 0) {
                    $this->cacheDispatchResult($toMatch, $routeArgs, $action);
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

    private function cacheDispatchResult(string $toMatch, array $routeArgs, callable $action) {
        if ($this->cacheEntryCount < $this->maxCacheEntries) {
            $this->cacheEntryCount++;
        } else {
            // Remove the oldest entry from the LRU cache to make room
            $unsetMe = key($this->cache);
            unset($this->cache[$unsetMe]);
        }

        $cacheKey = $toMatch;
        $this->cache[$cacheKey] = [$action, $routeArgs];
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
        if ($this->state !== Server::STOPPED) {
            throw new \LogicException(
                "Cannot add routes once the server has started"
            );
        }
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
            if ($action instanceof LoggerAware) {
                $this->loggerAwares[] = $action;
            } elseif (is_array($action) && is_object($action[0]) && $action[0] instanceof LoggerAware) {
                $this->loggerAwares[] = $action[0];
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
     * Assign the process-wide logger instance to route handlers requiring it
     *
     * @param PsrLogger $logger
     * @return void
     */
    public function setLogger(PsrLogger $logger) {
        $this->logger = $logger;
    }

    /**
     * React to server state changes
     *
     * Here we generate our dispatcher when the server notifies us that it is
     * ready to start (Server::STARTING). Because the Router is an instance of
     * Aerys\ServerObserver it will automatically be attached to the server as
     * an observer when passed to Aerys\Host::use().
     *
     * @param \Aerys\Server $server The notifying Aerys\Server instance
     * @return \Amp\Promise
     */
    public function update(Server $server): Promise {
        $observerPromises = [];
        foreach ($this->serverObservers as $serverObserver) {
            $observerPromises[] = $serverObserver->update($server);
        }
        switch ($this->state = $server->state()) {
            case Server::STOPPED:
                $this->routeDispatcher = null;
                break;
            case Server::STARTING:
                if (empty($this->routes)) {
                    $observerPromises[] = new Failure(new \DomainException(
                        "Router start failure: no routes registered"
                    ));
                    break;
                }
                $this->routeDispatcher = simpleDispatcher(function(RouteCollector $rc) {
                    foreach ($this->routes as list($method, $uri, $action)) {
                        $rc->addRoute($method, $uri, $action);
                    }
                });
                foreach ($this->loggerAwares as $loggerAware) {
                    $loggerAware->setLogger($this->logger);
                }
                break;
        }

        return any($observerPromises);
    }
}

<?php

namespace Aerys;

use Amp\Coroutine;
use Amp\Failure;
use Amp\Promise;
use Amp\Success;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use function FastRoute\simpleDispatcher;

class Router implements Responder, ServerObserver {
    private $state = Server::STOPPED;

    /** @var \FastRoute\Dispatcher */
    private $routeDispatcher;
    private $routes = [];
    private $actions = [];
    private $cache = [];
    private $cacheEntryCount = 0;
    private $maxCacheEntries = 512;

    /**
     * Set a router option.
     *
     * @param string $key
     * @param mixed $value
     * @throws \Error on unknown option key
     */
    public function setOption(string $key, $value) {
        switch ($key) {
            case "max_cache_entries":
                if (!is_int($value)) {
                    throw new \TypeError(sprintf(
                        "max_cache_entries requires an integer; %s specified",
                        is_object($value) ? get_class($value) : gettype($value)
                    ));
                }
                $this->maxCacheEntries = ($value < 1) ? 0 : $value;
                break;
            default:
                throw new \Error(
                    "Unknown Router option: {$key}"
                );
        }
    }

    /**
     * Route a request.
     *
     * @param \Aerys\Request $request
     * @param callable $next
     *
     * @return \Amp\Promise<\Aerys\Response>
     */
    public function respond(Request $request): Promise {
        return new Coroutine($this->dispatch($request));
    }

    private function dispatch(Request $request) {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        $toMatch = "{$method}\0{$path}";

        if (isset($this->cache[$toMatch])) {
            $responder = $this->cache[$toMatch];
            // Keep the most recently used entry at the back of the LRU cache
            unset($this->cache[$toMatch]);
            $this->cache[$toMatch] = $responder;
        } else {
            $match = $this->routeDispatcher->dispatch($method, $path);

            switch ($match[0]) {
                case Dispatcher::FOUND:
                    list(, $actions, $routeArgs) = $match;
                    list($responder, $middlewares) = $actions;

                    if (!empty($routeArgs)) {
                        $responder = new class($responder, $routeArgs) implements Responder {
                            private $responder;
                            private $args;

                            public function __construct(Responder $responder, array $args) {
                                $this->responder = $responder;
                                $this->args = $args;
                            }

                            public function respond(Request $request): Promise {
                                return $this->responder->respond($request, $this->args);
                            }
                        };
                    }

                    if (!empty($middlewares)) {
                        $responder = MiddlewareResponder::create($responder, $middlewares);
                    }

                    break;
                case Dispatcher::NOT_FOUND:
                    $status = HttpStatus::NOT_FOUND;
                    return new Response\HtmlResponse(makeGenericBody($status), [], $status);
                case Dispatcher::METHOD_NOT_ALLOWED:
                    $allowedMethods = implode(",", $match[1]);
                    $status = HttpStatus::METHOD_NOT_ALLOWED;
                    $body = makeGenericBody($status);
                    return new Response\HtmlResponse($body, ["Allow" => $allowedMethods], $status);
                default:
                    throw new \UnexpectedValueException(
                        "Encountered unexpected Dispatcher code"
                    );
            }

            if ($this->maxCacheEntries > 0) {
                $this->cacheDispatchResult($toMatch, $responder);
            }
        }

        return yield $responder->respond($request);
    }

    /**
     * Import a router or attach a Middleware to all routes.
     * Router imports do *not* import the options.
     *
     * @param Middleware|self $action
     *
     * @return self
     */
    public function use($action) {
        if (!($action instanceof self || $action instanceof Middleware)) {
            throw new \Error(\sprintf(
                "%s requires another %s instance or an instance of %s",
                __METHOD__,
                self::class,
                Responder::class
            ));
        }

        if ($action instanceof self) {
            /* merge routes in for better performance */
            foreach ($action->routes as $route) {
                $route[3] = array_merge($route[3], $this->actions);
                $this->routes[] = $route;
            }
        } else {
            $this->actions[] = $action;
            foreach ($this->routes as &$route) {
                $route[3][] = $action;
            }
        }

        return $this;
    }

    /**
     * Prefix all the (already defined) routes with a given prefix.
     *
     * @param string $prefix
     * @return self
     */
    public function prefix(string $prefix) {
        $prefix = trim($prefix, "/");

        if ($prefix != "") {
            foreach ($this->routes as &$route) {
                $route[1] = "/$prefix$route[1]";
            }

            $this->actions = [];
        }

        return $this;
    }

    private function cacheDispatchResult(string $toMatch, Responder $responder) {
        if ($this->cacheEntryCount < $this->maxCacheEntries) {
            $this->cacheEntryCount++;
        } else {
            // Remove the oldest entry from the LRU cache to make room
            $unsetMe = key($this->cache);
            unset($this->cache[$unsetMe]);
        }

        $cacheKey = $toMatch;
        $this->cache[$cacheKey] = $responder;
    }

    /**
     * Define an application route.
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
     * the trailing slash. Temporary redirects are used to redirect to the canonical URI
     * (with a trailing slash) to avoid search engine duplicate content penalties.
     *
     * @param string $method The HTTP method verb for which this route applies
     * @param string $uri The string URI
     * @param Responder|callable $responder
     * @param Middleware ...$middleware The middleware to apply to this route
     * @throws \Error on invalid empty parameters
     * @return self
     */
    public function route(string $method, string $uri, $responder, Middleware ...$middleware): Router {
        if ($this->state !== Server::STOPPED) {
            throw new \Error(
                "Cannot add routes once the server has started"
            );
        }

        if ($method === "") {
            throw new \Error(
                __METHOD__ . "() requires a non-empty string HTTP method at Argument 1"
            );
        }

        if (!\is_callable($responder) && !$responder instanceof Responder) {
            throw new \Error(\sprintf(
                "%s() requires a callable or an instance of %s at Argument 3",
                __METHOD__,
                Responder::class
            ));
        }


        $actions = array_merge($this->actions, $middleware);

        $uri = "/" . ltrim($uri, "/");

        // Special-case, otherwise we redirect just to the same URI again
        if ($uri === "/?") {
            $uri = "/";
        }

        if (substr($uri, -2) === "/?") {
            $canonicalUri = substr($uri, 0, -2);
            $redirectUri = substr($uri, 0, -1);
            $this->routes[] = [$method, $canonicalUri, $responder, $actions];
            $this->routes[] = [$method, $redirectUri, static function (Request $request): Response {
                $uri = $request->getUri();
                $path = rtrim($uri->getPath(), '/');
                if ($uri->getQuery()) {
                    $redirectTo = $path . "?" . $uri->getQuery();
                } else {
                    $redirectTo = $path;
                }

                return new Response\TextResponse(
                        "Canonical resource URI: {$path}",
                        ["Location" => $redirectTo],
                        HttpStatus::FOUND
                    );
            }, $actions];
        } else {
            $this->routes[] = [$method, $uri, $responder, $actions];
        }

        return $this;
    }

    private function bootRouteTarget($responder, array $actions): array {
        $middlewares = [];

        foreach ($actions as $key => $action) {
            if ($action instanceof Responder) {
                throw new \Error("Responders cannot be route actions");
            }

            if ($action instanceof Middleware) {
                $middlewares[] = $action;
            }
        }

        if (is_callable($responder)) {
            $responder = new CallableResponder($responder);
        }

        if (!$responder instanceof Responder) {
            throw new \Error("Responder must be callable or an instance of " . Responder::class);
        }

        return [$responder, $middlewares];
    }

    /**
     * React to server state changes.
     *
     * Here we generate our dispatcher when the server notifies us that it is
     * ready to start (Server::STARTING).
     *
     * @param Server $server
     * @return Promise
     */
    public function update(Server $server): Promise {
        switch ($this->state = $server->state()) {
            case Server::STOPPED:
                $this->routeDispatcher = null;
                break;
            case Server::STARTING:
                if (empty($this->routes)) {
                    return new Failure(new \Error(
                        "Router start failure: no routes registered"
                    ));
                }
                $this->routeDispatcher = simpleDispatcher(function ($rc) use ($server) {
                    $this->buildRouter($rc);
                });
                break;
        }

        return new Success;
    }

    private function buildRouter(RouteCollector $rc) {
        foreach ($this->routes as list($method, $uri, $responder, $actions)) {
            list($responder, $middlewares) = $this->bootRouteTarget($responder, $actions);
            $rc->addRoute($method, $uri, [$responder, $middlewares]);
        }
    }
}

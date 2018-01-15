<?php

namespace Aerys;

use Amp\Coroutine;
use Amp\Failure;
use Amp\Promise;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Psr\Log\LoggerInterface as PsrLogger;
use function FastRoute\simpleDispatcher;

final class Router implements Responder, ServerObserver {
    const DEFAULT_MAX_CACHE_ENTRIES = 512;

    /** @var bool */
    private $running = false;

    /** @var \FastRoute\Dispatcher */
    private $routeDispatcher;

    /** @var \Aerys\ErrorHandler */
    private $errorHandler;

    /** @var Responder|null */
    private $fallback;

    /** @var \SplObjectStorage */
    private $observers;

    private $routes = [];
    private $cache = [];
    private $cacheEntryCount = 0;
    private $maxCacheEntries;

    /**
     * @param int $maxCacheEntries Maximum number of route matches to cache.
     *
     * @throws \Error If $maxCacheEntries is less than zero.
     */
    public function __construct(int $maxCacheEntries = self::DEFAULT_MAX_CACHE_ENTRIES) {
        if ($maxCacheEntries < 0) {
            throw new \Error("The number of cache entries must be greater than or equal to zero");
        }

        $this->maxCacheEntries = $maxCacheEntries;
        $this->observers = new \SplObjectStorage;
    }

    /**
     * Route a request and dispatch it to the appropriate handler.
     *
     * @param Request $request
     *
     * @return Promise<\Aerys\Response>
     */
    public function respond(Request $request): Promise {
        return new Coroutine($this->dispatch($request));
    }

    private function dispatch(Request $request) {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        $toMatch = "{$method}\0{$path}";

        if (isset($this->cache[$toMatch])) {
            list($responder, $routeArgs) = $cache = $this->cache[$toMatch];
            $request->setAttribute(self::class, $routeArgs);

            // Keep the most recently used entry at the back of the LRU cache
            unset($this->cache[$toMatch]);
            $this->cache[$toMatch] = $cache;
        } else {
            $match = $this->routeDispatcher->dispatch($method, $path);

            switch ($match[0]) {
                case Dispatcher::FOUND:
                    list(, $responder, $routeArgs) = $match;
                    $request->setAttribute(self::class, $routeArgs);
                    break;

                case Dispatcher::NOT_FOUND:
                    if ($this->fallback !== null) {
                        return $this->fallback->respond($request);
                    }

                    $status = HttpStatus::NOT_FOUND;
                    return yield $this->errorHandler->handle($status, HttpStatus::getReason($status), $request);

                case Dispatcher::METHOD_NOT_ALLOWED:
                    $allowedMethods = implode(",", $match[1]);
                    $status = HttpStatus::METHOD_NOT_ALLOWED;

                    /** @var \Aerys\Response $response */
                    $response = yield $this->errorHandler->handle($status, HttpStatus::getReason($status), $request);
                    $response->setHeader("Allow", $allowedMethods);
                    return $response;

                default:
                    throw new \UnexpectedValueException(
                        "Encountered unexpected Dispatcher code"
                    );
            }

            if ($this->maxCacheEntries > 0) {
                $this->cacheDispatchResult($toMatch, $responder, $routeArgs);
            }
        }

        /** @var Responder $responder */
        return yield $responder->respond($request);
    }

    /**
     * Merge another router's routes into this router.
     *
     * Doing so might improve performance for request dispatching.
     *
     * @param self $router Router to merge.
     */
    public function merge(self $router) {
        if ($this->running) {
            throw new \Error("Cannot merge routers after the server has started");
        }

        foreach ($router->routes as $route) {
            $this->routes[] = $route;
        }

        $this->observers->addAll($router->observers);
    }

    /**
     * Prefix all currently defined routes with a given prefix.
     *
     * @param string $prefix
     */
    public function prefix(string $prefix) {
        if ($this->running) {
            throw new \Error("Cannot alter routes after the server has started");
        }

        $prefix = \trim($prefix, "/");

        if ($prefix !== "") {
            foreach ($this->routes as &$route) {
                $route[1] = "/$prefix$route[1]";
            }
        }
    }

    private function cacheDispatchResult(string $toMatch, Responder $responder, array $routeArgs) {
        if ($this->cacheEntryCount < $this->maxCacheEntries) {
            $this->cacheEntryCount++;
        } else {
            // Remove the oldest entry from the LRU cache to make room
            $unsetMe = key($this->cache);
            unset($this->cache[$unsetMe]);
        }

        $cacheKey = $toMatch;
        $this->cache[$cacheKey] = [$responder, $routeArgs];
    }

    /**
     * Define an application route.
     *
     * Matched URI route arguments are made available to responders as a request attribute
     * which may be accessed with the following that returns an array of strings:
     *
     *     $request->getAttribute(Router::class)
     *
     * Route URIs ending in "/?" (without the quotes) allow a URI match with or without
     * the trailing slash. Temporary redirects are used to redirect to the canonical URI
     * (with a trailing slash) to avoid search engine duplicate content penalties.
     *
     * @param string    $method The HTTP method verb for which this route applies.
     * @param string    $uri The string URI.
     * @param Responder $responder Responder invoked on a route match.
     *
     * @throws \Error If the server has started, or if $method is empty.
     */
    public function addRoute(string $method, string $uri, Responder $responder) {
        if ($this->running) {
            throw new \Error(
                "Cannot add routes once the server has started"
            );
        }

        if ($method === "") {
            throw new \Error(
                __METHOD__ . "() requires a non-empty string HTTP method at Argument 1"
            );
        }

        if ($responder instanceof ServerObserver) {
            $this->observers->attach($responder);
        }

        $uri = "/" . \ltrim($uri, "/");

        // Special-case, otherwise we redirect just to the same URI again
        if ($uri === "/?") {
            $uri = "/";
        }

        if (substr($uri, -2) === "/?") {
            $canonicalUri = \substr($uri, 0, -2);
            $redirectUri = \substr($uri, 0, -1);

            $this->routes[] = [$method, $canonicalUri, $responder];

            $this->routes[] = [$method, $redirectUri, new CallableResponder(static function (Request $request): Response {
                $uri = $request->getUri();
                $path = \rtrim($uri->getPath(), '/');

                if ($uri->getQuery()) {
                    $redirectTo = $path . "?" . $uri->getQuery();
                } else {
                    $redirectTo = $path;
                }

                return new Response\TextResponse(
                    "Canonical resource location: {$path}",
                    ["Location" => $redirectTo],
                    HttpStatus::FOUND
                );
            })];
        } else {
            $this->routes[] = [$method, $uri, $responder];
        }
    }

    /**
     * Specifies an instance of Responder that is used if no routes match.
     *
     * If no fallback is given, a 404 response is returned from `respond()` when no matching routes are found.
     *
     * @param Responder $responder
     *
     * @throws \Error If the server has started.
     */
    public function setFallback(Responder $responder) {
        if ($this->running) {
            throw new \Error("Cannot add fallback responder after the server has started");
        }

        $this->fallback = $responder;
    }

    public function onStart(Server $server, PsrLogger $logger, ErrorHandler $errorHandler): Promise {
        if (empty($this->routes)) {
            return new Failure(new \Error(
                "Router start failure: no routes registered"
            ));
        }

        $this->running = true;

        $this->routeDispatcher = simpleDispatcher(function (RouteCollector $rc) {
            foreach ($this->routes as list($method, $uri, $responder)) {
                $rc->addRoute($method, $uri, $responder);
            }
        });

        $this->errorHandler = $errorHandler;

        if ($this->fallback instanceof ServerObserver) {
            $this->observers->attach($this->fallback);
        }

        $promises = [];
        foreach ($this->observers as $observer) {
            $promises[] = $observer->onStart($server, $logger, $errorHandler);
        }

        return Promise\all($promises);
    }

    public function onStop(Server $server): Promise {
        $this->routeDispatcher = null;
        $this->running = false;

        $promises = [];
        foreach ($this->observers as $observer) {
            $promises[] = $observer->onStop($server);
        }

        return Promise\all($promises);
    }
}

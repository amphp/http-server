---
title: Router
permalink: /classes/router
---

The `Router` class is typically instantiated via the `router()` function.

## `use(callable|Middleware|Bootable|Monitor): self`

Installs an action global to the router.

## `prefix(string): self`

Prefixes every route (and even global actions) with a given prefix.

## `route(string $method, string $uri, callable|Middleware|Bootable|Monitor ...$actions): self`

Installs a route to be matched on a given `$method` and `$uri` combination.

In case of match, the route middlewares will be installed (including global `use()`'d middlewares) in _the order they were defined_. Similar for the chain of application callables.

## `$method(string $uri, callable|Middleware|Bootable|Monitor ...$actions): self` (aka `__call`)

Forwards the call to `route($method, $uri, $actions)`. (E.g. `get("/", $action)` is equivalent to `route("GET", "/", $action)`.)

## `monitor(): array`

See [`Monitor`](monitor.md), it returns an array of the following structure:

```php
[
    "GET" => [
        "/route" => [
            "MyMonitorClass" => [
                MyMonitorClass->monitor(),
                MyMonitorClass->monitor(),
                ... # if there are multiple instances of a same handler
            ],
            "OtherMonitorClass" => [...],
            ...
        ],
        ...
    ],
    "method" => [...],
    ...
]
```
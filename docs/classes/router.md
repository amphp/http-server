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

The Router is using [FastRoute from Nikita Popov](https://github.com/nikic/FastRoute) and inherits its dynamic possibilities. Hence it is possible to use dynamic routes, the matches will be in a third `$routes` array passed to the callable. This array will contain the matches keyed by the identifiers in the route.

A trailing `/?` on the route will make the slash optional and, when the route is called with a slash, issue a `302 Temporary Redirect` to the canonical route without trailing slash.

{:.note}
> Variable path segments can be defined using braces, e.g. `/users/{userId}`. Custom regular expressions can be used with a colon after the placeholder name, e.g. `/users/{userId:\d+}`. For a full list of route definition possiblities, have a look at the [FastRoute documentation](https://github.com/nikic/FastRoute#usage).

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

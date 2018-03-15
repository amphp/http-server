---
title: Responder
permalink: /responder
---

The `Responder` interface is the main interface to create responses.
It's `respond()` method receives a `Request` and must return a `Promise` that resolves to a `Response`.

A `Responder` can either directly generate a response or delegate to another `Responder`. An example for such a delegating `Responder` is the [`Router`](https://github.com/amphp/http-server-router).

The `Responder` interface is meant to be implemented by classes.
For simpler use cases, you can use `CallableResponder`, which can wrap any `callable` and automatically executes the `callable` as [coroutine](https://amphp.org/amp/coroutines) if it returns a `Generator`.

## Example

The following example assumes you're inside a coroutine.
You can find the full example in `./examples/hello-world.php`.

```php
$servers = [
    Socket\listen("0.0.0.0:1337"),
    Socket\listen("[::]:1337"),
];

$server = new Server($servers, new CallableResponder(function (Request $request) {
    return new Response(Status::OK, [
        "content-type" => "text/plain; charset=utf-8"
    ], "Hello, World!");
}));

yield $server->start();
```

---
title: Request Handler
permalink: /request-handler
---

Requests are handled by `RequestHandler` instances. Yes, Captain Obvious!

Usually a `RequestHandler` directly generates a response, but it might also delegate to another `RequestHandler`.
An example for such a delegating `RequestHandler` is the [`Router`](https://github.com/amphp/http-server-router).

The `RequestHandler` interface is meant to be implemented by custom classes.
For very simple use cases, you can use `CallableRequestHandler`, which can wrap any `callable` and automatically executes the `callable` as [coroutine](https://amphp.org/amp/coroutines) if it returns a `Generator`.

## Example

The following example assumes you're inside a coroutine.
You can find the full example in `./examples/hello-world.php`.

```php
$servers = [
    Socket\listen("0.0.0.0:1337"),
    Socket\listen("[::]:1337"),
];

$server = new Server($servers, new CallableRequestHandler(function (Request $request) {
    return new Response(Status::OK, [
        "content-type" => "text/plain; charset=utf-8"
    ], "Hello, World!");
}));

yield $server->start();
```

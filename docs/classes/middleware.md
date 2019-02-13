---
title: Middleware
permalink: /classes/middleware
---
Middleware allows pre-processing of requests and post-processing of responses.
Apart from that, a middleware can also intercept the request processing and return a response without delegating to the passed request handler.
Classes have to implement the `Middleware` interface for that.

{:.note}
> The naming of `RequestHandler` and `Middleware` is chosen based on [PSR-15](https://www.php-fig.org/psr/psr-15/), but the API is adjusted to meet the requirements of asynchronous PHP, i.e. using promises as placeholders for return values.

{:.note}
> Middleware generally follows other words like soft- and hardware with its plural.
> However, we use the term _middlewares_ to refer to multiple objects implementing the `Middleware` interface.

## `handleRequest(Request $request, RequestHandler $next): Promise`

`handleRequest(Request, RequestHandler): Promise` is the only method of the `Middleware` interface.
If the `Middleware` doesn't handle the request itself, it should delegate the response creation to the received `RequestHandler`. The promise returned from this method should resolve to an instance of `Response`.

## `stack(RequestHandler $handler, Middleware ...$middlewares): RequestHandler`

Multiple middlewares can be stacked by using `Amp\Http\Server\Middleware\stack()`, which accepts a `RequestHandler` as first argument and a variable number of `Middleware` instances.

{:.image-80}
![Middleware interaction](../latex/middleware.png)

## Example

```php
$handler = new CallableRequestHandler(function (Request $request) {
   return new Response(Status::OK, [
       "content-type" => "text/plain; charset=utf-8"
   ], "Hello, World!");
});

$middleware = new class implements Middleware {
    public function handleRequest(Request $request, RequestHandler $next): Promise {
        return call(function () use ($request, $next) {
            $requestTime = microtime(true);

            $response = yield $next->handleRequest($request);
            $response->setHeader("x-request-time", microtime(true) - $requestTime);

            return $response;
        });
    }
};

$server = new Server($servers, Middleware\stack($handler, $middleware), $logger);
```

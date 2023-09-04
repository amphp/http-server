# amphp/http-server

AMPHP is a collection of event-driven libraries for PHP designed with fibers and concurrency in mind.
This package provides a non-blocking, concurrent HTTP/1.1 and HTTP/2 application server for PHP based on [Revolt](https://revolt.run/).
Several features are provided in separate packages, such as the [WebSocket component](https://github.com/amphp/websocket-server).

## Features

- [Static file serving](https://github.com/amphp/http-server-static-content)
- [WebSockets](https://github.com/amphp/websocket-server)
- [Dynamic app endpoint routing](https://github.com/amphp/http-server-router)
- [Request body parser](https://github.com/amphp/http-server-form-parser)
- [Sessions](https://github.com/amphp/http-server-session)
- Full TLS support
- Customizable GZIP compression
- Supports HTTP/1.1 and HTTP/2
- Middleware hooks
- [CORS](https://github.com/labrador-kennel/http-cors) (3rd party)

## Requirements

- PHP 8.1+

## Installation

This package can be installed as a [Composer](https://getcomposer.org/) dependency.

```bash
composer require amphp/http-server
```

Additionally, you might want to install the `nghttp2` library to take advantage of FFI to speed up and reduce the memory usage.

## Usage

This library provides access to your application through the HTTP protocol, accepting client requests and forwarding those requests to handlers defined by your application which will return a response.

Incoming requests are represented by `Request` objects. A request is provided to an implementor of `RequestHandler`, which defines a `handleRequest()` method returning an instance of `Response`.

```php
public function handleRequest(Request $request): Response
```

Request handlers are covered in greater detail in the [`RequestHandler` section](#requesthandler).

This HTTP server is built on top of [the Revolt event-loop](https://revolt.run) and [the non-blocking concurrency framework Amp](https://amphp.org/amp).
Thus it inherits full support of all their primitives and it is possible to use all the non-blocking libraries built on top of Revolt.

> **Note**
> In general, you should make yourself familiar with [the `Future` **concept**](https://amphp.org/amp#future), with [coroutines](https://amphp.org/amp#coroutines), and be aware of the several [combinator](https://amphp.org/amp#combinators) functions to really succeed at using the HTTP server.

### Blocking I/O

Nearly every built-in function of PHP is doing blocking I/O, that means, the executing thread (mostly equivalent to the process in the case of PHP) will effectively be halted until the response is received.
A few examples of such functions: `mysqli_query`, `file_get_contents`, `usleep` and many more.

A good rule of thumb is: Every built-in PHP function doing I/O is doing it in a blocking way, unless you know for sure it doesn't.

There are [libraries providing implementations that use non-blocking I/O](https://amphp.org/packages). You should use these instead of the built-in functions.

We cover the most common I/O needs, such as [network sockets](https://github.com/amphp/socket), [file access](https://github.com/amphp/file), [HTTP requests](https://github.com/amphp/http-client) and [websockets](http://github.com/amphp/websocket-client), [MySQL](https://github.com/amphp/mysql) and [Postgres](http://github.com/amphp/postgres) database clients, and [Redis](https://github.com/amphp/redis). If using blocking I/O or long computations are necessary to fulfill a request, consider using the [Parallel library](https://github.com/amphp/parallel) to run that code in a separate process or thread.

> **Warning**
> Do not use any blocking I/O functions in the HTTP server.

```php
// Here's a bad example, DO NOT do something like the following!

$handler = new ClosureRequestHandler(function () {
    sleep(5); // Equivalent to a blocking I/O function with a 5 second timeout

    return new Response;
});

// Start a server with this handler and hit it twice.
// You'll have to wait until the 5 seconds are over until the second request is handled.
```

### Creating an HTTP Server

Your application will be served by an instance of `HttpServer`. This library provides `SocketHttpServer`, which will be suitable for most applications, built on components found in this library and in [`amphp/socket`](https://github.com/amphp/socket).

To create an instance of `SocketHttpServer` and listen for requests, minimally four things are required:

* an instance of [`RequestHandler`](#requesthandler) to respond to incoming requests,
* an instance of [`ErrorHander`](#errorhandler) to provide responses to invalid requests,
* an instance of `Psr\Log\LoggerInterface`, and
* at least one host+port on which to listen for connections.

```php
<?php
use Amp\ByteStream;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;

require __DIR__.'/vendor/autoload.php';

// Note any PSR-3 logger may be used, Monolog is only an example.
$logHandler = new StreamHandler(ByteStream\getStdout());
$logHandler->pushProcessor(new PsrLogMessageProcessor());
$logHandler->setFormatter(new ConsoleFormatter());

$logger = new Logger('server');
$logger->pushHandler($logHandler);

$requestHandler = new class() implements RequestHandler {
    public function handleRequest(Request $request) : Response
    {
        return new Response(
            status: HttpStatus::OK,
            headers: ['Content-Type' => 'text/plain'],
            body: 'Hello, world!',
        );
    }
};

$errorHandler = new DefaultErrorHandler();

$server = SocketHttpServer::createForDirectAccess($logger);
$server->expose('127.0.0.1:1337');
$server->start($requestHandler, $errorHandler);

// Serve requests until SIGINT or SIGTERM is received by the process.
Amp\trapSignal([SIGINT, SIGTERM]);

$server->stop();
```

The above example creates a simple server which sends a plain-text response to every request received.

`SocketHttpServer` provides two static constructors for common use-cases in addition to the normal constructor for more advanced and custom uses.

- `SocketHttpServer::createForDirectAccess()`: Used in the example above, this creates an HTTP application server suitable for direct network access. Adjustable limits are imposed on connections per IP, total connections, and concurrent requests (10, 1000, and 1000 by default, respectively). Response compression may be toggled on or off (on by default) and request methods are limited to a known set of HTTP verbs by default.
- `SocketHttpServer::createForBehindProxy()`: Creates a server appropriate for use when behind a proxy service such as nginx. This static constructor requires a list of trusted proxy IPs (with optional subnet masks) and an enum case of `ForwardedHeaderType` (corresponding to either `Forwarded` or `X-Forwarded-For`) to parse the original client IP from request headers. No limits are imposed on the number of connections to the server, however the number of concurrent requests are limited (1000 by default, adjustable or can be removed). Response compression may be toggled on or off (on by default). Request methods are limited to a known set of HTTP verbs by default.

If neither of these methods serve your application needs, the `SocketHttpServer` constructor may be used directly. This provides an enormous amount of flexibility in how incoming connections client connections are created and handled, but will require more code to create. The constructor requires the user to pass an instance of `SocketServerFactory`, used to create client `Socket` instances (both components of the [`amphp/socket`](https://github.com/amphp/socket) library), and an instance of `ClientFactory`, which appropriately creates [`Client`](#request-clients) instances which are attached to each `Request` made by the client.

### `RequestHandler`

Incoming requests are represented by `Request` objects. A request is provided to an implementor of `RequestHandler`, which defines a `handleRequest()` method returning an instance of `Response`.

```php
public function handleRequest(Request $request): Response
```

Each client request (i.e., call to `RequestHandler::handleRequest()`) is executed within a separate [coroutine](https://amphp.org/architecture#coroutines) so requests are automatically handled cooperatively within the server process. When a request handler waits on [non-blocking I/O](#blocking-io), other client requests are processed in concurrent coroutines. Your request handler may itself create other coroutines using [`Amp\async()`](https://amphp.org/amp#coroutines) to execute multiple tasks for a single request.

Usually a `RequestHandler` directly generates a response, but it might also delegate to another `RequestHandler`.
An example for such a delegating `RequestHandler` is the [`Router`](https://github.com/amphp/http-server-router).

The `RequestHandler` interface is meant to be implemented by custom classes.
For very simple use cases or quick mocking, you can use `CallableRequestHandler`, which can wrap any `callable` and accepting a `Request` and returning a `Response`.

### Middleware

Middleware allows pre-processing of requests and post-processing of responses.
Apart from that, a middleware can also intercept the request processing and return a response without delegating to the passed request handler.
Classes have to implement the `Middleware` interface for that.

> **Note**
> Middleware generally follows other words like soft- and hardware with its plural.
> However, we use the term _middlewares_ to refer to multiple objects implementing the `Middleware` interface.

```php
public function handleRequest(Request $request, RequestHandler $next): Response
```

`handleRequest` is the only method of the `Middleware` interface. If the `Middleware` doesn't handle the request itself, it should delegate the response creation to the received `RequestHandler`.

```php
function stackMiddleware(RequestHandler $handler, Middleware ...$middleware): RequestHandler
```

Multiple middlewares can be stacked by using `Amp\Http\Server\Middleware\stackMiddleware()`, which accepts a `RequestHandler` as first argument and a variable number of `Middleware` instances. The returned `RequestHandler` will invoke each middleware in the provided order.

```php
$requestHandler = new class implements RequestHandler {
    public function handleRequest(Request $request): Response
    {
        return new Response(
            status: Status::OK,
            headers: ["content-type" => "text/plain; charset=utf-8"],
            body: "Hello, World!",
        );
    }
}

$middleware = new class implements Middleware {
    public function handleRequest(Request $request, RequestHandler $next): Response
    {
        $requestTime = microtime(true);

        $response = $next->handleRequest($request);
        $response->setHeader("x-request-time", microtime(true) - $requestTime);

        return $response;
    }
};

$stackedHandler = Middleware\stackMiddleware($requestHandler, $middleware);
$errorHandler = new DefaultErrorHandler();

// $logger is a PSR-3 logger instance.
$server = SocketHttpServer::createForDirectAccess($logger);
$server->expose('127.0.0.1:1337');
$server->start($stackedHandler, $errorHandler);
```

### `ErrorHandler`

An `ErrorHander` is used by the HTTP server when a malformed or otherwise invalid request is received. The `Request` object is provided if one constructed from the incoming data, but may not always be set.

```php
public function handleError(
    int $status,
    ?string $reason = null,
    ?Request $request = null,
): Response
```

This library provides `DefaultErrorHandler` which returns a stylized HTML page as the response body. You may wish to provide a different implementation for your application, potentially using multiple in conjunction with a [router](https://github.com/amphp/http-server-router).

### `Request`

#### Constructor

It is rare you will need to construct a `Request` object yourself, as they will typically be provided to `RequestHandler::handleRequest()` by the server.

```php
/**
 * @param string $method The HTTP method verb.
 * @param array<string>|array<string, array<string>> $headers An array of strings or an array of string arrays.
 */
public function __construct(
    private readonly Client $client,
    string $method,
    Psr\Http\Message\UriInterface $uri,
    array $headers = [],
    Amp\ByteStream\ReadableStream|string $body = '',
    private string $protocol = '1.1',
    ?Trailers $trailers = null,
)
```

#### Methods

```php
public function getClient(): Client
```

Returns the [`Сlient`](#request-clients) sending the request

```php
public function getMethod(): string
```

Returns the HTTP method used to make this request, e.g. `"GET"`.

```php
public function setMethod(string $method): void
```

Sets the request HTTP method.

```php
public function getUri(): Psr\Http\Message\UriInterface
```

Returns the request [`URI`](https://www.php-fig.org/psr/psr-7/#35-psrhttpmessageuriinterface).

```php
public function setUri(Psr\Http\Message\UriInterface $uri): void
```

Sets a new [`URI`](https://www.php-fig.org/psr/psr-7/#35-psrhttpmessageuriinterface) for the request.

```php
public function getProtocolVersion(): string
```

Returns the HTTP protocol version as a string (e.g. "1.0", "1.1", "2").

```php
public function setProtocolVersion(string $protocol)
```

Sets a new protocol version number for the request.

```php
/** @return array<non-empty-string, list<string>> */
public function getHeaders(): array
```

Returns the headers as a string-indexed array of arrays of strings or an empty array if no headers have been set.

```php
public function hasHeader(string $name): bool
```

Checks if given header exists.

```php
/** @return list<string> */
public function getHeaderArray(string $name): array
```

Returns the array of values for the given header or an empty array if the header does not exist.

```php
public function getHeader(string $name): ?string
```

Returns the value of the given header.
If multiple headers are present for the named header, only the first header value will be returned.
Use `getHeaderArray()` to return an array of all values for the particular header.
Returns `null` if the header does not exist.

```php
public function setHeaders(array $headers): void
```

Sets the headers from the given array.

```php
/** @param array<string>|string $value */
public function setHeader(string $name, array|string $value): void
```

Sets the header to the given value(s).
All previous header lines with the given name will be replaced.

```php
/** @param array<string>|string $value */
public function addHeader(string $name, array|string $value): void
```

Adds an additional header line with the given name.

```php
public function removeHeader(string $name): void
```

Removes the given header if it exists.
If multiple header lines with the same name exist, all of them are removed.

```php
public function getBody(): RequestBody
```

Returns the request body. The [`RequestBody`](#body) allows streamed and buffered access to an [`InputStream`](https://amphp.org/byte-stream/).

```php
public function setBody(ReadableStream|string $body)
```

Sets the stream for the message body

> **Note**
> Using a string will automatically set the `Content-Length` header to the length of the given string.
> Setting an [`ReadableStream`](https://amphp.org/byte-stream/#readablestream) will remove the `Content-Length` header.
> If you know the exact content length of your stream, you can add a `content-length` header _after_ calling `setBody()`.

```php
/** @return array<non-empty-string, RequestCookie> */
public function getCookies(): array
```

Returns all [cookies](https://amphp.org/http/cookies) in associative map of cookie name to `RequestCookie`.

```php
public function getCookie(string $name): ?RequestCookie
```

Gets a [cookie](https://amphp.org/http/cookies) value by name or `null`.

```php
public function setCookie(RequestCookie $cookie): void
```

Adds a [`Cookie`](https://amphp.org/http/cookies) to the request.

```php
public function removeCookie(string $name): void
```

Removes a cookie from the request.

```php
public function getAttributes(): array
```

Returns an array of all the attributes stored in the request's mutable local storage.

```php
public function removeAttributes(): array
```

Removes all request attributes from the request's mutable local storage.

```php
public function hasAttribute(string $name): bool
```

Check whether an attribute with the given name exists in the request's mutable local storage.

```php
public function getAttribute(string $name): mixed
```

Retrieve a variable from the request's mutable local storage.

> **Note**
> Name of the attribute should be namespaced with a vendor and package namespace, like classes.

```php
public function setAttribute(string $name, mixed $value): void
```

Assign a variable to the request's mutable local storage.

> **Note**
> Name of the attribute should be namespaced with a vendor and package namespace, like classes.

```php
public function removeAttribute(string $name): void
```

Removes a variable from the request's mutable local storage.

```php
public function getTrailers(): Trailers
```

Allows access to the [`Trailers`](#trailers) of a request.

```php
public function setTrailers(Trailers $trailers): void
```

Assigns the [`Trailers`](#trailers) object to be used in the request.

### Request Clients

Client-related details are bundled into `Amp\Http\Server\Driver\Client` objects returned from `Request::getClient()`. The `Client` interface provides methods to retrieve the remote and local socket addresses and TLS info (if applicable).

### `Response`

The **`Response`** class represents an HTTP response. A **`Response`** is returned by [request handlers](#requesthandler) and [middleware](#middleware).

#### Constructor

```php
/**
 * @param int $code The HTTP response status code.
 * @param array<string>|array<string, array<string>> $headers An array of strings or an array of string arrays.
 */
public function __construct(
    int $code = HttpStatus::OK,
    array $headers = [],
    Amp\ByteStream\ReadableStream|string $body = '',
    ?Trailers $trailers = null,
)
```

#### Destructor

Invokes dispose handlers (i.e. functions that registered via `onDispose()` method).

> **Note**
> Uncaught exceptions from the dispose handlers will be forwarded to the [event loop](https://revolt.run) error handler.

#### Methods

```php
public function getBody(): Amp\ByteStream\ReadableStream
```

Returns the [stream](https://amphp.org/byte-stream/) for the message body.

```php
public function setBody(Amp\ByteStream\ReadableStream|string $body)
```

Sets the [stream](https://amphp.org/byte-stream/) for the message body.

> **Note**
> Using a string will automatically set the `Content-Length` header to the length of the given string.
> Setting an [`ReadableStream`](https://amphp.org/byte-stream/#readablestream) will remove the `Content-Length` header.
> If you know the exact content length of your stream, you can add a `content-length` header _after_ calling `setBody()`.

```php
/** @return array<non-empty-string, list<string>> */
public function getHeaders(): array
```

Returns the headers as a string-indexed array of arrays of strings or an empty array if no headers have been set.

```php
public function hasHeader(string $name): bool
```

Checks if given header exists.

```php
/** @return list<string> */
public function getHeaderArray(string $name): array
```

Returns the array of values for the given header or an empty array if the header does not exist.

```php
public function getHeader(string $name): ?string
```

Returns the value of the given header.
If multiple headers are present for the named header, only the first header value will be returned.
Use `getHeaderArray()` to return an array of all values for the particular header.
Returns `null` if the header does not exist.

```php
public function setHeaders(array $headers): void
```

Sets the headers from the given array.

```php
/** @param array<string>|string $value */
public function setHeader(string $name, array|string $value): void
```

Sets the header to the given value(s).
All previous header lines with the given name will be replaced.

```php
/** @param array<string>|string $value */
public function addHeader(string $name, array|string $value): void
```

Adds an additional header line with the given name.

```php
public function removeHeader(string $name): void
```

Removes the given header if it exists.
If multiple header lines with the same name exist, all of them are removed.

```php
public function getStatus(): int
```

Returns the response status code.

```php
public function getReason(): string
```

Returns the reason phrase describing the status code.

```php
public function setStatus(int $code, string | null $reason): void
```

Sets the numeric HTTP status code (between 100 and 599) and reason phrase. Use null for the reason phrase to use the default phrase associated with the status code.

```php
/** @return array<non-empty-string, ResponseCookie> */
public function getCookies(): array
```

Returns all [cookies](https://amphp.org/http/cookies) in an associative map of cookie name to `ResponseCookie`.

```php
public function getCookie(string $name): ?ResponseCookie
```

Gets a [cookie](https://amphp.org/http/cookies) value by name or `null` if no cookie with that name is present.

```php
public function setCookie(ResponseCookie $cookie): void
```

Adds a [cookie](https://amphp.org/http/cookies) to the response.

```php
public function removeCookie(string $name): void
```

Removes a [cookie](https://amphp.org/http/cookies) from the response.

```php
/** @return array<string, Push> Map of URL strings to Push objects. */
public function getPushes(): array
```

Returns list of push resources in an associative map of URL strings to `Push` objects.

```php
/** @param array<string>|array<string, array<string>> $headers */
public function push(string $url, array $headers): void
```

Indicate resources which a client likely needs to fetch. (e.g. `Link: preload` or HTTP/2 Server Push).

```php
public function isUpgraded(): bool
```

Returns `true` if a detach callback has been set, `false` if none.

```php
/** @param Closure(Driver\UpgradedSocket, Request, Response): void $upgrade */
public function upgrade(Closure $upgrade): void
```

Sets a callback to be invoked once the response has been written to the client and changes the status of the response to `101 Switching Protocols`. The callback receives an instance of `Driver\UpgradedSocket`, the `Request` which initiated the upgrade, and this `Response`.

The callback may be removed by changing the status to something other than 101.

```php
public function getUpgradeCallable(): ?Closure
```

Returns the upgrade function if present.

```php
/** @param Closure():void $onDispose */
public function onDispose(Closure $onDispose): void
```

Registers a function that is invoked when the Response is discarded. A response is discarded either once it has been written to the client or if it gets replaced in a middleware chain.

```php
public function getTrailers(): Trailers
```

Allows access to the [`Trailers`](#trailers) of a response.

```php
public function setTrailers(Trailers $trailers): void
```

Assigns the [`Trailers`](#trailers) object to be used in the response. Trailers are sent once the entire response body has been set to the client.

### Body

`RequestBody`, returned from `Request::getBody()`, provides buffered and streamed access to the request body.
Use the streamed access to handle large messages, which is particularly important if you have larger message limits (like tens of megabytes) and don't want to buffer it all in memory.
If multiple people are uploading large bodies concurrently, the memory might quickly get exhausted.

Hence, incremental handling is important, accessible via [the `read()` API of `Amp\ByteStream\ReadableStream`](https://amphp.org/byte-stream#readablestream).

In case a client disconnects, the `read()` fails with an `Amp\Http\Server\ClientException`.
This exception is thrown for both the `read()` and `buffer()` API.

> **Note**
> `ClientException`s do not *need* to be caught. You may catch them if you want to continue, but don't have to. The Server will silently end the request cycle and discard that exception then.

Instead of setting the generic body limit high, you should consider increasing the body limit only where needed, which is dynamically possible with the `increaseSizeLimit()` method on `RequestBody`.

> **Note**
> `RequestBody` itself doesn't provide parsing of form data. You can use [`amphp/http-server-form-parser`](https://github.com/amphp/http-server-form-parser) if you need it.

#### Constructor

Like `Request`, it is rare to need to construct a `RequestBody` instance as one will be provided as part of the `Request`.

```php
public function __construct(
    ReadableStream|string $stream,
    ?Closure $upgradeSize = null,
)
```

#### Methods

```php
public function increaseSizeLimit(int $limit): void
```

Increases the body size limit dynamically to allow individual request handlers to handle larger request bodies than the default set for the HTTP server.

### Trailers

The **`Trailers`** class allows access to the trailers of an HTTP request, accessible via `Request::getTrailers()`. `null` is returned if trailers are not expected on the request.
`Trailers::await()` returns a `Future` which is resolved with an `HttpMessage` object providing methods to access the trailer headers.

```php
$trailers = $request->getTrailers();
$message = $trailers?->await();
```

## Bottlenecks

The HTTP server won't be the bottleneck. Misconfiguration, use of blocking I/O, or inefficient applications are.

The server is well-optimized and can handle tens of thousands of requests per second on typical hardware while maintaining a high level of concurrency of thousands of clients.

But that performance will decrease drastically with inefficient applications.
The server has the nice advantage of classes and handlers being always loaded, so there's no time lost with compilation and initialization.

A common trap is to begin operating on big data with simple string operations, requiring many inefficient big copies.
Instead, streaming should be used where possible for larger request and response bodies.

The problem really is CPU cost.
Inefficient I/O management (as long as it is non-blocking!) is just delaying individual requests.
It is recommended to dispatch simultaneously and eventually bundle multiple independent I/O requests via Amp's combinators, but a slow handler will slow down every other request too.
While one handler is computing, all the other handlers can't continue.
Thus it is imperative to reduce computation times of the handlers to a minimum.

## Examples

Several examples can be found in the [`./examples`](https://github.com/amphp/http-server/tree/3.x/examples) directory of the [repository](https://github.com/amphp/http-server).
These can be executed as normal PHP scripts on the command line.

```bash
php examples/hello-world.php
```

You can then access the example server at [`http://localhost:1337/`](http://localhost:1337/) in your browser.

## Security

If you discover any security related issues, please use the private security issue reporter instead of using the public issue tracker.

## License

The MIT License (MIT). Please see [LICENSE](./LICENSE) for more information.

### 0.8.3

 - Disabled compression if `ext-zlib` isn't available (#267)

### 0.8.2

- Fixed an issue when an HTTP/2 response is written immediately (#258).
- Performance recommendations are now logged as warnings when starting the server depending the mode set in options (debug or production), the value of the zend.assertions ini setting, and if the xdebug extension is loaded (related to #256).
- `Request::setBody()` and `Response::setBody()` now additionally accepts any value that can be cast to a string (such as integers, floats, and objects with a `__toString()` method) as the body content (#254).

### 0.8.1

- Fixed an issue where latency was increased dramatically on some systems compared to v0.7.x (#252).
- Fixed the `content-length` header being removed by `CompressionMiddleware` if the body was not long enough to be compressed.
- `ExceptionMiddleware` now writes the exception to the log to mimic the default behavior if it were not used.

### 0.8.0

This version is a major refactor, with many components being moved to separate libraries.

- Routing is now in [amphp/http-server-router](https://github.com/amphp/http-server-router)
- Static file serving (formerly `Root`) is now in [amphp/http-server-static-content](https://github.com/amphp/http-static-content)
- Form body parsing is now in [amphp/http-server-form-parser](https://github.com/amphp/http-server-form-parser)
- Multi-processing has been refactored to be general purpose and moved to [amphp/cluster](https://github.com/amphp/cluster)
- The WebSocket server component is now in [amphp/websocket-server](https://github.com/amphp/websocket-server)

A server is now created using an array of socket servers, an instance of `RequestHandler`, and a PSR-3 logger instance.

```php
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Server;
use Psr\Log\NullLogger;

Amp\Loop::run(function () {
    $sockets = [
        Amp\Socket\listen("0.0.0.0:1337"),
        Amp\Socket\listen("[::]:1337"),
    ];
    
    $server = new Server($sockets, new CallableRequestHandler(function (Request $request) {
        return new Response(Status::OK, [
            "content-type" => "text/plain; charset=utf-8"
        ], "Hello, World!");
    }), new NullLogger);

    yield $server->start();

    // Stop the server gracefully when SIGINT is received.
    // This is technically optional, but it is best to call Server::stop().
    Amp\Loop::onSignal(SIGINT, function (string $watcherId) use ($server) {
        Amp\Loop::cancel($watcherId);
        yield $server->stop();
    });
});
```

Request handling has been changed significantly. Requests are now handled through interfaces modeled after [PSR-15](https://www.php-fig.org/psr/psr-15/), modified to be used in a non-blocking context.

Requests are handled by objects of classes implementing `RequestHandler`, which may be wrapped with any number of objects of classes implementing `Middleware`. Middleware is combined with a request handler using the `Middleware\stack()` function, returning an instance of `RequestHandler` that can be used with `Server` or anywhere else a `RequestHandler` may be used (such as routes in the routing library).

Please see the [documentation](https://amphp.org/http-server) for more information.

### 0.7.4

 - Fixed an issue where the timing of WebSocket writes could cause out of order messages.

### 0.7.3

 - Allow `amphp/file ^0.3`
 - Fixed sending pending responses when shutting down the server (#211)
 - More portable CPU count detection (#207)
 - WebSocket updates:
    - Reject websocket frames if RSV is not equal to `0` (will update as extensions are supported).
    - Accept zero-length frames starting a message.
    - Reject continuations if there's no started message.
    - Disable streaming for frames. A single frame is now always buffered. A message can still be streamed via multiple frames.

### 0.7.2

 - Fixed reading request port with HTTP/2

### 0.7.1

 - Fixed connection hangs if the process is forked while serving a request. See #182 and #192.

### 0.7.0

 - Fixed incorrect log level warning.
 - Fixed issue with referenced IPC socket blocking server shutdown.
 - Added support for unix sockets.
 - Added support for wildcard server names such as `localhost:*`, `*:80` and `*:*`.
 - Fixed buggy HTTP/1 pipelining.
 - Handle promises returned from generators in the config file correctly.
 - Added `-u` / `--user` command line option.
 - Correctly decode URL parameters with `urldecode()` instead of `rawurldecode()`.
 - Fixed freeze of websocket reading with very low `maxFramesPerSecond` and `maxBytesPerMinute`.
 - Removed `Router::__call()` magic, use `Router::route()` instead.

### 0.6.2

Retag of `v0.6.0`, as `v0.6.1` has been tagged wrongly, which should have been `v0.7.0`.

### 0.6.1

**Borked release.** Should have been `v0.7.0` and has been tagged as `v0.7.0` now.

### 0.6.0

Initial release based on Amp v2.

- Config files must return an instance of `Host` or an array of `Host` instances.
- `Aerys\Response::stream()` renamed to `write()` so that `Aerys\Response` may implement `Amp\ByteStream\OutputStream`.
- `Aerys\WebSocket\Endpoint::send()` split into three methods: `send()`, `broadcast()`, and `multicast()`.
- `Aerys\Body` removed. `Request::getBody()` returns an instance of `Amp\ByteStream\Message`.

### 0.5.0

_No information available._

### 0.4.7

_No information available._

### 0.4.6

_No information available._

### 0.4.5

_No information available._

### 0.4.4

_No information available._

### 0.4.3

 - Implemented monitoring system.
 - Always properly close HTTP/1.0 connections.
 - Fixed wildcard address matching.

### 0.4.2

_No information available._

### 0.4.1

 - Fixed caching issues in `Router` if multiple methods exist for specific URIs.
 
### 0.4.0

_No information available._

### 0.3.0

_No information available._

### 0.2.0

_No information available._

### 0.1.0

_No information available._

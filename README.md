# Aerys

A performant non-blocking HTTP/1.1 application/websocket server written in PHP.

> **IMPORTANT:** Aerys is highly volatile pre-alpha software. Everything is subject to change
> without notice, but you already knew that didn't you? :)

## Installation

```bash
$ git clone --recursive https://github.com/rdlowrey/Aerys.git
$ cd Aerys
$ git submodule update --init --recursive
```

If you have problems cloning via the `https` method use SSH instead (it always works):

```bash
$ git clone --recursive git@github.com:rdlowrey/Aerys.git
```

## Dependencies

#### REQUIRED

- PHP 5.5+ for unencrypted servers
- PHP 5.6+ required for TLS encryption

*Userland Dependencies*

- [Alert](https://github.com/rdlowrey/Alert) :: IO and event reactors
- [After](https://github.com/rdlowrey/After) :: Simple concurrency primitives
- [Auryn](https://github.com/rdlowrey/Auryn) :: Dependency injector
- [Amp](https://github.com/rdlowrey/Amp) :: Non-blocking threaded job dispatcher

> **NOTE:** The userland dependencies listed above are automatically retrieved for you if you use
> the installation instructions shown here.

#### OPTIONAL PHP EXTENSIONS

- [pecl/libevent](http://pecl.php.net/package/libevent) Though optional, production servers *SHOULD*
employ the libevent extension
- [pecl/pthreads](http://pecl.php.net/package/pthreads) Though optional, productions servers *SHOULD*
have pthreads if they wish to serve static files. Filesystem IO operations will block the main event
loop without this extension.

> **NOTE:** Windows users can find DLLs for both pecl extensions at the
> [windows.php.net download index](http://windows.php.net/downloads/pecl/releases/).


## Running a Server

To start a server pass a config file to the aerys binary using the `-c, --config` switches:

```bash
$ bin/aerys -c "/path/to/config.php"
```

Use the `-h, --help` switches for more instructions.

## Example Configs

Every Aerys application is initialized using a PHP config file containing `Aerys\App` instances
and server-wide option settings.

##### Hello World

Any returned string is treated as a response. Aerys handles the HTTP protocol details of sending
the returned string to clients (similar to the classic PHP web SAPI).

```php
<?php
require '/path/to/aerys/src/bootstrap.php';

// Send a basic 200 response to all requests on port 80.
$myApp = (new Aerys\App)->addResponder(function() {
    return '<html><body><h1>OMG PHP is Webscale!</h1></body></html>';
});
```

##### The HTTP Request Environment

Aerys passes request details to applications using a map structure similar to the PHP web SAPI's
`$_SERVER`. The example below returns the contents of the request environment in a response.

```php
<?php
require '/path/to/aerys/src/bootstrap.php';

// Responders are passed an environment array
$myApp = (new Aerys\App)->addResponder(function(array $request) {
    return "<html><body><pre>" . print_r($request, TRUE) . "</pre></body></html>";
});
```

> **NOTE:** Unlike the PHP web SAPI there is no concept of request "superglobals" in Aerys. All data
> describing client requests is passed directly to applications in the `$request` array argument.

##### The HTTP Response

Applications may optionally customize headers, status codes and reason phrases by returning an
`Aerys\Response` instance.

```php
<?php
require '/path/to/aerys/src/bootstrap.php';

$myApp = (new Aerys\App)->addResponder(function($request) {
    return (new Aerys\Response)
        ->setStatus(200)
        ->setHeader('X-My-Header', 42)
        ->setBody('<html><body><h1>ZOMG PGP!!!11</h1></body></html>');
});
```

##### Streaming Responses

@TODO

##### Threaded Responses

Aerys applications aren't forced to use non-blocking semantics. Any server with access to the
`pecl/pthreads` extension may specify threaded routes which, when matched, will execute inside
dedicated worker threads for synchronous processing in a style similar to the PHP web SAPI.

Applications can mix and match threaded routes with any of Aerys's other functionality. In the
following example we demonstrate the use of a threaded route alongside a standard non-blocking
route and fallback responder.

```php
<?php
require '/path/to/aerys/src/bootstrap.php';

function myNonBlockingRoute($request) {
    return "<html><body><h1>Non-blocking route</h1></body></html>";
}

function myThreadedRoute($request) {
    return "<html><body><h1>Hurray for synchronous execution!</h1></body></html>";
}

function myFallbackResponder($request) {
    return "<html><body><h1>Everything else is routed to our fallback</h1></body></html>";
}

$myApp = (new Aerys\App)
    ->setPort(1337)
    ->addRoute('GET', '/non-blocking', 'myNonBlockingRoute')
    ->addThreadRoute('GET', '/', 'myNonBlockingRoute')
    ->addThreadRoute('GET', '/threaded', 'myThreadedRoute')
    ->addResponder('myFallbackResponder')
;

```

##### Server-Wide Options

Aerys has many server-wide options available for customization. To assign these options, the
server binary looks for any variables in your config file's global namespace prefixed with a
double underscore ($__) that case-insensitively match server option directives. The server operates
with sensible defaults, but if you want to customize such values this is the place to do it. Note
again that server-wide options apply to ALL apps registered on your server.

The example below sets only a small number of the available server options. To see a full and
up-to-date list of possible options please consult the `Aerys\Server` source code.

```php
<?php
require '/path/to/aerys/src/bootstrap.php';

$__maxConnections = 2500;
$__maxRequests = 100;
$__keepAliveTimeout = 5;
$__defaultContentType = 'text/html';
$__defaultTextCharset = 'utf-8';
$__allowedMethods = ['GET', 'HEAD', 'POST', 'PUT'];

$myApp = (new Aerys\App)->addResponder(function() {
    $body = '<html><body><h1>Hello, world.</h1></body></html>';
    return [$status = 200, $reason = 'OK', $headers = [], $body];
});
```

##### Named Virtual Hosts

Each `App` instance in the config file corresponds to a host on your server. Users may specify a
separate app for each domain/subdomain they wish to expose. For example ...

```php
<?php
require '/path/to/aerys/src/bootstrap.php';

// mysite.com
$mySite = (new Aerys\App)
    ->setPort(80) // <-- Defaults to 80, so this isn't technically necessary
    ->setName('mysite.com')
    ->addResponder(function() {
        return '<html><body><h1>mysite.com</h1></body></html>';
    });

// subdomain.mysite.com
$mySubdomain = (new Aerys\App)
    ->setName('subdomain.mysite.com')
    ->addResponder(function() {
        return '<html><body><h1>subdomain.mysite.com</h1></body></html>';
    });

// omgphpiswebscale.com
$omgPhpIsWebscale = (new Aerys\App)
    ->setName('omgphpiswebscale.com')
    ->addResponder(function() {
        return '<html><body><h1>omgphpiswebscale.com</h1></body></html>';
    });
```

##### Serving Static Files

Simply add the `App::setDocumentRoot` declaration to add HTTP/1.1-compliant static file
serving to your applications.

```php
<?php
require '/path/to/aerys/src/bootstrap.php';

// Any URI not matched by another handler is treated as a static file request
$myApp = (new Aerys\App)
    ->setName('mysite.com')
    ->addRoute('GET', '/', 'MyClass::myGetHandlerMethod')
    ->addRoute('POST', '/', 'MyClass::myPostHandlerMethod')
    ->setDocumentRoot('/path/to/static/file/root/');
```

> **NOTE:** Though static file serving will work without the `pecl/pthreads` extension (to ease
> development) the deployment of a static file server in production without pthreads is strongly
> discouraged. Without this extension static file transmission can block the server's event loop and
> cause severe slowdowns. Ensure you have pthreads before serving static files in production.

##### Basic Routing

Aerys provides built-in URI and HTTP method routing to map requests to the application endpoints of
your choosing. Any valid callable or class instance method may be specified as a route target. Note
that class methods have their instances automatically provisioned and injected affording routed
applications all the benefits of clean code and dependency injection.

```php
<?php
require '/path/to/aerys/src/bootstrap.php';

// Any PHP callable may be specified as a route target (instance methods, too!)
$myApp = (new Aerys\App)
    ->addRoute('GET', '/', 'MyClass::myGetHandler')
    ->addRoute('POST', '/', 'MyClass::myPostHandler')
    ->addRoute('PUT', '/', 'MyClass::myPutHandler')
    ->addRoute('GET', '/info', 'MyClass::anotherInstanceMethod')
    ->addRoute('GET', '/static', 'SomeClass::staticMethod')
    ->addRoute('GET', '/function', 'some_global_function')
    ->addRoute('GET', '/lambda', function() { return '<html><body>hello</body></html>'; })
    ->setDocumentRoot('/path/to/static/files'); // <-- only used if no routes match
```

> **NOTE:** Aerys uses [FastRoute](https://github.com/nikic/FastRoute) for routing. Full regex
> functionality is available in all route definitions.

URI arguments parsed from request URIs are available in the request environment array's
`'URI_ROUTE_ARGS'` key.

##### Dependency Injection

@TODO

##### Asynchronous Responses

The most important thing to remember about Aerys (and indeed any server running inside a non-blocking
event loop) is that your application callables must not block execution of the event loop with slow
operations (like synchronous database or disk IO). Application callables may employ `yield` to act
as a `Generator` and cooperatively multitask with the server using non-blocking libraries.


```php
<?php
require '/path/to/aerys/src/bootstrap.php';

function asyncResponder($request) {
    $result = (yield asyncMultiply(6, 7));
    yield "<html><body><h1>6 x 7 = {$result}</h1></body></html>";
};


function multiAsyncResponder($request) {
    list($result1, $result2, $result3) = (yield [
        asyncMultiply(6, 7),
        asyncMultiply(3, 3),
        asyncMultiply(2, 9)
    ]);

    yield "<html><body><h1>{$result1} | {$result2} | {$result3}</h1></body></html>";
}

$myApp = (new Aerys\App)
    ->setPort(1337)
    ->addRoute('GET', '/', 'asyncResponder')
    ->addRoute('GET', '/multi', 'multiAsyncResponder');
```

> **NOTE:** Threaded routes are free of the restrictions of non-blocking routes but they come at the
> cost of scalability as worker thread resource become a bottleneck with synchronous resources. An
> application using strictly non-blocking logic can easily handle thousands of simultaneous users on
> a single machine.


##### TLS Encryption

@TODO

##### Websockets

@TODO

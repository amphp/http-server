# Aerys

A performant non-blocking HTTP/1.1 application/websocket server written in PHP.

## Installation

```bash
$ git clone --recursive https://github.com/rdlowrey/Aerys.git
$ cd Aerys
$ git submodule update --init --recursive
```

## Running a Server

To start a server pass a config file (`-c` or `--config`) to the aerys binary:

```bash
$ bin/aerys --config = "/path/to/config.php"
```

Get help via `-h` or `--help`:

```bash
$ bin/aerys --h
```

##### Hello World

Any string or seekable stream resource may be returned as a response. Resources are automatically
streamed to clients.

```php
<?php
require __DIR__ . '/path/to/aerys/src/bootstrap.php';

// Send a basic 200 response to all requests on port 80.
$myApp = (new Aerys\App)->addResponder(function() {
    return '<html><body><h1>OMG PHP is Webscale!</h1></body></html>';
});
```

##### The HTTP Request Environment

Aerys passes request details to applications using a map structure similar to the PHP web SAPI's
`$_SERVER`. The example below returnes the contents of the request environment as a response.

```php
<?php
require __DIR__ . '/path/to/aerys/src/bootstrap.php';

// Responders are passed an environment array and Request ID
$myApp = (new Aerys\App)->addResponder(function(array $request) {
    return "<html><body><pre>" . print_r($request, TRUE) . "</pre></body></html>";
});
```

##### The HTTP Response


```php
<?php
require __DIR__ . '/path/to/aerys/src/bootstrap.php';

$myApp = (new Aerys\App)->addResponder(function($request) {
    return (new Aerys\Response)
        ->setStatus(200)
        ->setHeader('X-My-Header', 42)
        ->setBody('<html><body><h1>ZOMG PGP!!!11</h1></body></html>');
});
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
require __DIR__ . '/path/to/aerys/src/bootstrap.php';

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

Each `App` instance corresponds to a host on your server. Users may add as many host names as they
like.

```php
<?php
require __DIR__ . '/path/to/aerys/src/bootstrap.php';

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
require __DIR__ . '/path/to/aerys/src/bootstrap.php';

// Any URI not matched by another handler is treated as a static file request
$myApp = (new Aerys\App)
    ->setName('mysite.com')
    ->addRoute('GET', '/', 'MyClass::myGetHandlerMethod')
    ->addRoute('POST', '/', 'MyClass::myPostHandlerMethod')
    ->setDocumentRoot('/path/to/static/file/root/');
```

##### Basic Routing

While you can add your own user responder callables, it's usually better to take advantage of the
built-in routing functionality to map URIs and HTTP method verbs to specific application endpoints.
Any valid callable or class instance method may be specified as a route target. Note that class
methods have their instances automatically provisioned and injected affording routed applications
all the benefits of clean code and dependency injection.

```php
<?php
require __DIR__ . '/path/to/aerys/src/bootstrap.php';

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

##### Asynchronous Responses

The most important thing to remember about Aerys (and indeed any server running inside a non-blocking
event loop) is that your application callables must not block execution of the event loop with slow
operations (like synchronous database or disk IO). Application callables may employ `yield` to act
as a `Generator` and cooperatively multitask with the server using non-blocking libraries.


```php
<?php
require __DIR__ . '/path/to/aerys/src/bootstrap.php';

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


##### TLS Encryption

@TODO

##### Websockets

@TODO


## Dependencies

#### REQUIRED

- PHP 5.5+ for unencrypted servers
- PHP 5.6+ required to use TLS encryption
- [Alert](https://github.com/rdlowrey/Alert) IO and timer event reactors
- [Auryn](https://github.com/rdlowrey/Auryn) A dependency injector to bootstrap applications

#### OPTIONAL PHP EXTENSIONS

- [pecl/libevent](http://pecl.php.net/package/libevent) Though optional, production servers *SHOULD*
employ the libevent extension
- [pecl/pthreads](http://pecl.php.net/package/pthreads) Though optional, productions servers *SHOULD*
have pthreads if they wish to serve static files. Filesystem IO operations will block the main event
loop without this extension.

> **NOTE:** Windows users can find DLLs for both pecl extensions at the
> [windows.php.net download index](http://windows.php.net/downloads/pecl/releases/).

#### TESTING

- [Artax](https://github.com/rdlowrey/Artax) Required to execute the full integration test suite
without skipping some cases

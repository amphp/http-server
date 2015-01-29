# Aerys

A performant non-blocking HTTP/1.1 application/websocket server written in PHP.

> **IMPORTANT:** Aerys is highly volatile pre-alpha software; everything can
> and likely will change without notice.

## Installation

```bash
$ git clone https://github.com/rdlowrey/Aerys.git
$ cd Aerys
$ composer install
$ chmod +x bin/aerys
```

## Running a Server

To start a server pass a config file to the aerys binary using the `-c, --config` switches:

```bash
$ bin/aerys -c "/path/to/config.php"
```

Use the `-h, --help` switches for more instructions.

## Example Configs

Every Aerys application is initialized using a PHP config file containing `Aerys\Host` instances
and server-wide option settings. Here's an example:

```php
<?php

// --- global server options --- //
const AERYS_OPTIONS = [
    "MAX_CONNECTIONS" => 1000,
    "MAX_REQUESTS" => 100,
    "KEEP_ALIVE_TIMEOUT" => 5,
    "MAX_HEADER_BYTES" => 8192,
    "MAX_BODY_BYTES" => 2097152,
    "DEFAULT_CONTENT_TYPE" => 'text/html',
    "DEFAULT_TEXT_CHARSET" => 'utf-8',
    "AUTO_REASON_PHRASE" => true,
    "SEND_SERVER_TOKEN" => false,
    "SOCKET_BACKLOG_SIZE" => 128,
    "ALLOWED_METHODS" => 'GET HEAD PUT POST PATCH OPTIONS',
];

// --- mysite.com --- //
(new Aerys\Host)
    ->setName('mysite.com')
    ->addRoute('GET', '/', 'myIndexCallable')
    ->addRoute('GET', '/hello', 'myHelloCallable')
    ->addResponder('myFallbackResponder')
;

// --- static.mysite.com --- //
(new Aerys\Host)
    ->setName('static.mysite.com')
    ->setRoot('/path/to/static/files')
;
```

##### Hello World

Any returned string is treated as a response. Aerys handles the HTTP protocol details of sending
the returned string to clients (similar to the classic PHP web SAPI).

```php
<?php

// Send a basic 200 response to all requests on port 80.
(new Aerys\Host)->addResponder(function() {
    return '<html><body><h1>OMG PHP is Webscale!</h1></body></html>';
});
```

##### The HTTP Request Environment

Aerys passes request details to applications using a map structure similar to the PHP web SAPI's
`$_SERVER`. The example below returns the contents of the request environment in a response.

```php
<?php

// Responders are passed an environment array
(new Aerys\Host)->addResponder(function(array $request) {
    return "<html><body><pre>" . print_r($request, TRUE) . "</pre></body></html>";
});
```

> **NOTE:** Unlike the PHP web SAPI there is no concept of request "superglobals" in Aerys. All data
> describing client requests is passed directly to applications in the `$request` array argument.

##### The HTTP Response

Applications may optionally customize headers, status codes and reason phrases by returning an
associative array.

```php
<?php

(new Aerys\Host)->addResponder(function($request) {
    return [
        'status' => 200,
        'header' => 'X-My-Header: 42',
        'body'   =>'<html><body><h1>ZOMG PGP!!!11</h1></body></html>',
    ];
});
```

##### Async Generated Responses

When an application requires IO to generate a response it uses generators to `yield` Promise
instances or other generators.

```php
<?php

(new Aerys\Host)->addResponder(function($request) {
    $asyncResult = yield someAsyncCall();
    yield "status" => 200;
    yield "header" => "X-My-Header1: hola";
    yield "header" => "X-My-Header2: amigo";
    yield "body" => "<html>{$asyncResult}</br>";
    $moreAsyncResults = yield anotherAsyncCall();
    yield "body" => "The rest: {$moreAsyncResults}</html>"
});
```

##### Named Virtual Hosts

Each `Host` instance in the config file corresponds to a host on your server. Users may specify a
separate app for each domain/subdomain they wish to expose. For example ...

```php
<?php

// --- mysite.com --- //
(new Aerys\Host)
    ->setPort(80) // <-- Defaults to 80, so not technically necessary
    ->setName('mysite.com')
    ->addResponder(function() {
        return '<html><body><h1>mysite.com</h1></body></html>';
    })
;

// --- subdomain.mysite.com --- //
(new Aerys\Host)
    ->setName('subdomain.mysite.com')
    ->addResponder(function() {
        return '<html><body><h1>subdomain.mysite.com</h1></body></html>';
    })
;

// --- omgphpiswebscale.com --- //
(new Aerys\Host)
    ->setName('omgphpiswebscale.com')
    ->addResponder(function() {
        return '<html><body><h1>omgphpiswebscale.com</h1></body></html>';
    })
;
```

##### Serving Static Files

Simply add the `Host::setRoot` declaration to add HTTP/1.1-compliant static file
serving to your applications.

```php
<?php

// Any URI not matched by another handler is treated as a static file request
(new Aerys\Host)
    ->setName('mysite.com')
    ->addRoute('GET', '/', 'MyClass::myGetHandlerMethod')
    ->addRoute('POST', '/', 'MyClass::myPostHandlerMethod')
    ->setRoot('/path/to/static/file/root/')
;
```

##### Basic Routing

Aerys provides built-in URI and HTTP method routing to map requests to the application endpoints of
your choosing. Any valid callable or class instance method may be specified as a route target. Note
that class methods have their instances automatically provisioned and injected affording routed
applications all the benefits of clean code and dependency injection.

```php
<?php

// Any PHP callable may be specified as a route target (instance methods, too!)
(new Aerys\Host)
    ->addRoute('GET', '/', 'MyClass::myGetHandler')
    ->addRoute('POST', '/', 'MyClass::myPostHandler')
    ->addRoute('PUT', '/', 'MyClass::myPutHandler')
    ->addRoute('GET', '/info', 'MyClass::anotherInstanceMethod')
    ->addRoute('GET', '/static', 'SomeClass::staticMethod')
    ->addRoute('GET', '/function', 'some_global_function')
    ->addRoute('GET', '/lambda', function() { return '<html>hello</html>'; })
    ->setRoot('/path/to/static/files') // <-- only used if no routes match
;
```

> **NOTE:** Aerys uses [FastRoute](https://github.com/nikic/FastRoute) for routing. Full regex
> functionality is available in all route definitions.

URI arguments parsed from request URIs are available in the request environment array's
`'URI_ROUTE_ARGS'` key.

##### Dependency Injection

@TODO

##### TLS Encryption

@TODO

##### Websockets

@TODO

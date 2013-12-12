# Aerys

High-performance non-blocking HTTP/1.1 web application, websocket and custom socket protocol server
written in PHP. Awesomeness ensues.

## Installation

```bash
$ git clone --recursive https://github.com/rdlowrey/Aerys.git
```

## Running a Server

To start a server simply pass a config file to the aerys binary:

```bash
$ bin/aerys --config = "/path/to/config.php"
```

To run a static file server on port 80 without a configuration file:

```bash
$ bin/aerys -r /path/to/static/files
```

Customizing static file server details:

```bash
$ bin/aerys --root /path/to/static/files --port 1337 --ip 127.0.0.1
```

Get help via `-h` or `--help`:

```bash
$ bin/aerys --h
```

## Learn by Example

##### Hello World

Any string or seekable stream resource may be returned as a response. Resources are automatically
streamed to clients.

```php
<?php
use Aerys\Framework\App;
require __DIR__ . '/path/to/aerys/autoload.php';

// Send a basic 200 response to all requests on port 80.
$myApp = (new App)->addResponder(function() {
    return '<html><body><h1>OMG PHP is Webscale!</h1></body></html>';
});
```

##### The Request Environment

Aerys passes request details to applications using a map structure similar to the PHP web SAPI's
`$_SERVER`. The example below returnes the contents of the request environment as a response.

```php
<?php
use Aerys\Framework\App;
require __DIR__ . '/path/to/aerys/autoload.php';

// Responders are passed an environment array and Request ID
$myApp = (new App)->addResponder(function($request) {
    $environment = print_r($request, TRUE);
    return "<html><body><pre>{$environment}</pre></body></html>";
});
```

##### Customizing Status, Reason and Headers

Aerys abstracts HTTP protocol details and allows applications to return strings and resources
directly in response to client requests. However, apps may also customize headers and status codes
by returning a map structure as shown below. Any array or `ArrayAccess` instance may be returned and
the available (case-sensitive) keys are:

- status
- reason
- headers
- body
- export_callback (explained later)


```php
<?php
use Aerys\Framework\App;
require __DIR__ . '/path/to/aerys/autoload.php';

$myApp = (new App)->addResponder(function() {
    return [
        'status' => 200,
        'reason' => 'OK',
        'headers' => [
            'X-My-Header: some value',
            'Another-Header: some other value'
        ],
        'body' = '<html><body><h1>ZOMG PGP!!!11</h1></body></html>'
    ];
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
use Aerys\Framework\App;
require __DIR__ . '/path/to/aerys/autoload.php';

$__maxConnections = 2500;
$__maxRequests = 100;
$__keepAliveTimeout = 5;
$__defaultContentType = 'text/html';
$__defaultTextCharset = 'utf-8';
$__allowedMethods = ['GET', 'HEAD', 'POST', 'PUT'];

$myApp = (new App)->addResponder(function() {
    $body = '<html><body><h1>Hello, world.</h1></body></html>';
    return [$status = 200, $reason = 'OK', $headers = [], $body];
});
```

##### Named Virtual Hosts

Each `App` instance corresponds to a host on your server. Users may add as many host names as they
like.

```php
<?php
use Aerys\Framework\App;
require __DIR__ . '/path/to/aerys/autoload.php';

// mysite.com
$mySite = (new App)
    ->setPort(80) // <-- Defaults to 80, so this isn't technically necessary
    ->setName('mysite.com')
    ->addResponder(function() {
        return '<html><body><h1>mysite.com</h1></body></html>';
    });

// subdomain.mysite.com
$mySubdomain = (new App)
    ->setName('subdomain.mysite.com')
    ->addResponder(function() {
        return '<html><body><h1>subdomain.mysite.com</h1></body></html>';
    });

// omgphpiswebscale.com
$omgPhpIsWebscale = (new App)
    ->setName('omgphpiswebscale.com')
    ->addResponder(function() {
        return '<html><body><h1>omgphpiswebscale.com</h1></body></html>';
    });
```

##### Serving Static Files

Simply add the `App::setDocumentRoot` declaration to add fully HTTP/1.1-compliant static file
serving to your applications.

```php
<?php
use Aerys\Framework\App;
require __DIR__ . '/path/to/aerys/autoload.php';

// Any URI not matched by another handler is treated as a static file request
$myApp = (new App)
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
use Aerys\Framework\App;
require __DIR__ . '/path/to/aerys/autoload.php';

// Any PHP callable may be specified as a route target (instance methods, too!)
$myApp = (new App)
    ->addRoute('GET', '/', 'MyClass::myGetHandler')
    ->addRoute('POST', '/', 'MyClass::myPostHandler')
    ->addRoute('PUT', '/', 'MyClass::myPutHandler')
    ->addRoute('GET', '/info', 'MyClass::anotherInstanceMethod')
    ->addRoute('GET', '/static', 'SomeClass::staticMethod')
    ->addRoute('GET', '/function', 'some_global_function')
    ->addRoute('GET', '/lambda', function() { return '<html><body>hello</body></html>'; })
    ->addRoute('GET', '/$#arg1/$#arg2/$arg3', 'SomeClass::routeArgs')
    ->setDocumentRoot('/path/to/static/files'); // <-- only used if no routes match
```

##### Asynchronous Responses

The most important thing to remember about Aerys (and indeed any server running inside a non-blocking
event loop) is that your application callables must not block execution of the event loop with slow
operations (like synchronous database or disk IO). Application callables may employ `yield` to act
as a `Generator` and cooperatively multitask with the server using non-blocking libraries. This
functionality means we can avoid the ["callback hell"](http://callbackhell.com/) often associated
with non-blocking code without resorting to additional libraries.


```php
<?php
use Aerys\Framework\App;
require __DIR__ . '/path/to/aerys/autoload.php';

// in reality we'd use a non-blocking lib to do something here
function asyncMultiply($x, $y, callable $onCompletion) {
    $result = $x*$y;
    $onCompletion($result); // <--$result is returned to our generator's yield expression
}

// The key-value construction assumes the non-blocking lib accepts an "onComplete" callback
// as its final parameter
function sexyAsyncResponder($request) {
    $x = 6; $y = 7;
    $result = (yield 'asyncMultiply' => [$x, $y]);
    yield "<html><body><h1>Chicks dig brevity ({$result})!</h1></body></html>";
};

// yielding a callable directly without the key-value form
function uglyAsyncResponder($request) {
    $x = 6; $y = 7;
    $result = (yield function(callable $onCompletion) use ($x, $y) {
        asyncMultiply($x, $y, $onCompletion);
    });
    yield "<html><body><h1>Ugly, but it works ({$result})!</h1></body></html>";
};

// Using multiple yield statements in a single responder
function multiAsyncResponder($request) {
    $x = 1; $y = 2;
    $result1 = (yield 'asyncMultiply' => [$x, $y]);
    $result2 = (yield 'asyncMultiply' => [$result1, $y]);
    $result3 = (yield 'asyncMultiply' => [$result2, $y]);
    
    yield "<html><body><h1>Async! All of the things ({$result3})!</h1></body></html>";
}

$myApp = (new App)
    ->setPort(1338)
    ->addRoute('GET', '/', 'sexyAsyncResponder')
    ->addRoute('GET', '/ugly', 'uglyAsyncResponder')
    ->addRoute('GET', '/multi', 'multiAsyncResponder');
```

To demonstrate that we aren't limited to functions lets look at an example using instance methods.
Here we use a non-scalar callable key in our yield statement to execute a local instance method.
Note how *any* valid callable may be passed in the key portion of the `yield` statement:

```php
<?php
use Aerys\Framework\App;
require __DIR__ . '/path/to/aerys/autoload.php';

class MyHandler {
    private $redis;
    function __construct(AsyncRedisClient $redis) {
        $this->redis = $redis;
    }
    function doSomething() {
        $asyncResult = (yield [$this->redis, 'get'] => 'mykey');
        yield $this->manipulateMyAsyncData($asyncResult);
    }
    private function manipulateMyAsyncData($asyncResult) {
        return "<html><body>{$asyncResult}</body></html>";
    }
}

$myApp = (new App)->addRoute('GET', '/', 'MyHandler::doSomething');
```

##### TLS Encryption

Any host may utilize TLS encryption by passing appropriate settings to `App::encrypt`. This
applies to *all* communications on the host (even websockets). The below example adds an additional
host on port 80 that redirects all traffic to the encrypted application on port 443.

```php
<?php
use Aerys\Framework\App;
require __DIR__ . '/path/to/aerys/autoload.php';

$encryptedApp = (new App)
    ->setPort(443)
    ->encrypt([
        'local_cert' => __DIR__ . '/examples/support/tls_cert.pem',
        'passphrase' => '42 is not a legitimate passphrase',
        'ciphers'    => 'RC4-SHA:HIGH:!MD5:!aNULL:!EDH'
    ])->setDocumentRoot(__DIR__ . '/support/docroot');

// Because we can, let's redirect all unencrypted traffic on port 80 to port 443
$redirectApp = (new App)
    ->setPort(80)
    ->addResponder(function($request) {
        $status = 302;
        $reason = 'Moved Temporarily';
        $headers = [
            'Location: https://127.0.0.1' . $request['REQUEST_URI']
        ];
        $body = '<html><body>Encryption required; redirecting.</body></html>';

        return [$status, $reason, $headers, $body];
    }
);
```

##### Websockets

Adding websockets to your server is as simple as calling `App::addWebsocket` with the relevant
URI path and the name of a websocket endpoint class. All an endpoint must do is implement the
websocket [`Endpoint`][endpoint-interface] interface and Aerys will auto-instantiate and provision
the class. In the below example we add the endpoint alongside a static document root that serves the
HTML and javascript needed to run the websocket demo.

```php
<?php
use Aerys\Framework\App;
require __DIR__ . '/path/to/aerys/autoload.php';
require __DIR__ . '/support/Ex401_WebsocketEchoEndpoint.php';

$myWebsocketApp = (new Aerys\Framework\App)
    ->setDocumentRoot(__DIR__ . '/support/docroot/websockets')
    ->addWebsocket('/echo', 'Ex401_WebsocketEchoEndpoint', $options = []);
```

> **IMPORTANT:** Websocket endpoints are NOT normal HTTP resources.
>
> If you actually want to see something happen with a websocket endpoint you need to simultaneously
> serve an HTML resource with javascript that connects to that endpoint to send data back and forth
> over the websocket connection. If you serve a lone websocket endpoint and try to open the relevant
> URI in a browser you'll receive a *426 Upgrade Required* response. Websockets don't work that way;
> they aren't pages you can simply open in a browser.

Websocket endpoints may cooperatively multitask with the server in the same way as HTTP responders
using generators and the `yield` expression. Any string or stream yielded (or returned) is sent to
the client that initiated the relevant event. Endpoints may use the websocket `Broker` API for
complex operations and extended functionality:

- `Broker::sendText($socketIdOrArrayOfIds, $stringOrStream)`
- `Broker::sendBinary($socketIdOrArrayOfIds, $stringOrStream)`
- `Broker::getStats($socketId)`
- `Broker::getEnvironment($socketId)`
- `Broker::close($socketIdOrArrayOfIds, $optionalCloseCode, $optionalCloseReason)`

An extremely simple websocket `Endpoint` implementation is shown below:

```php
<?php

use Aerys\Responders\Websocket\Broker,
    Aerys\Responders\Websocket\Message,
    Aerys\Responders\Websocket\Endpoint;

class Rot13Endpoint implements Endpoint {

    function onOpen(Broker $broker, $socketId) {
        return json_encode(['hello' => 'Welcome!']);
    }

    function onMessage(Broker $broker, $socketId, Message $msg) {
        $stringToEncode = $msg->getPayload();
        return json_encode(['rot13' => str_rot13($stringToEncode)]);
    }

    function onClose(Broker $broker, $socketId, $code, $reason) {
        // The client ($socketId) disconnected, but we don't care so we
        // don't do anything here.
    }
}
```

##### Reverse Proxying

Aerys can also act as a reverse proxy and route requests through to backend servers. Using this
functionality we can do nifty things like layer websocket endpoints on top of an existing
application that uses the traditional PHP web SAPI. The example below will intercept any requests
made to the `/echo` URI and handle them as websockets while passing all other traffic without a
match in our document root through to the backend server.

```php
<?php
require __DIR__ . '/path/to/aerys/autoload.php';
require __DIR__ . '/support/Ex401_WebsocketEchoEndpoint.php';

$myWebsocketApp = (new Aerys\Framework\App)
    ->reverseProxyTo('192.168.1.5:1500', ['proxyPassHeaders' => [
        'Host'            => '$host',
        'X-Forwarded-For' => '$remoteAddr',
        'X-Real-Ip'       => '$serverAddr'
    ]])
    ->setDocumentRoot(__DIR__ . '/support/docroot/websockets')
    ->addWebsocket('/echo', 'Ex401_WebsocketEchoEndpoint');
```

## Dependencies

#### REQUIRED

- PHP 5.5+ Aerys utilizes features introduced in 5.5: (`Generator`, `finally` keyword)
- [Alert](https://github.com/rdlowrey/Alert) The magic hamster running the wheel
- [Auryn](https://github.com/rdlowrey/Auryn) A dependency injector used to simplify app configuration

Both the Auryn and Alert dependencies are linked to the Aerys repository as git submodules. They
will be fetched automatically as long as you pass the `--recursive` options when cloning the repo.

#### OPTIONAL (FOR MOAR FAST)

- [ext/libevent](http://pecl.php.net/package/libevent) Though optional, production servers *SHOULD*
employ the libevent extension -- PHP struggles to handle > ~250 simultaneous clients natively
- [ext/http](http://pecl.php.net/package/pecl_http) Benchmarks demonstrate ~10% speed increase with
the HTTP extension enabled
- _ext/openssl_ OpenSSL support is needed if you wish to TLS-encrypt socket connections to your
server. This extension is compiled into most PHP distributions by default.

> **NOTE:** Windows users can find DLLs for both ext/libevent and ext/http at the
> [windows.php.net download index](http://windows.php.net/downloads/pecl/releases/).

#### TESTING

- [vfsStream](https://github.com/mikey179/vfsStream) Required to execute the full unit test suite
without skipping some cases
- [Artax](https://github.com/rdlowrey/Artax) Required to execute the full integration test suite
without skipping some cases

[endpoint-interface]: https://github.com/rdlowrey/Aerys/blob/master/src/Aerys/Responders/Websocket/Endpoint.php "Websocket Endpoint"

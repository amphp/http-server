# Aerys

High-performance non-blocking HTTP/1.1 web application, websocket and custom socket protocol server
written entirely in PHP. Awesomeness ensues.

## Installation

```bash
$ git clone --recursive https://github.com/rdlowrey/Aerys.git
```

No. There's no composer right now. I'll add it when the project is more mature.

## Running Your Server

To start a server simply pass your config file to the aerys binary:

```bash
$ bin/aerys --config = "/path/to/config.php"
```

Running a static file server on port 80 without any configuration:

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

## Basic Config Examples

##### Hello World

Any string or seekable stream resource may be returned as a response. Resources are automatically
streamed to clients whereas strings are obviously fully buffered in memory.

```php
<?php
use Aerys\Framework\App;
require __DIR__ . '/path/to/aerys/autoload.php';

// Send a basic 200 response to all requests on port 80.
$myApp = (new App)->addUserResponder(function() {
    return '<html><body><h1>OMG PHP is Webscale!</h1></body></html>';
});
```

##### The Request Environment

Aerys passes an application exhaustive details of each request in a `$_SERVER`-style CGI-like array.
The example below prints the data stored in the request's ASGI environment array.

```php
<?php
use Aerys\Framework\App;
require __DIR__ . '/path/to/aerys/autoload.php';

// Responders are passed an environment array and Request ID
$myApp = (new App)->addUserResponder(function($asgiEnv, $requestId) {
    $environment = print_r($asgiEnv, TRUE);
    return "<html><body><pre>{$environment}</pre></body></html>";
});
```

##### Customizing Status Codes & Headers

While Aerys makes responses a snap by allowing the return of only an entity body, you can also
customize response headers and status information by returning an indexed array as shown here:

```php
<?php
use Aerys\Framework\App;
require __DIR__ . '/path/to/aerys/autoload.php';

// Response must be in the form [$status, $reason, $headersArray, $body]
$myApp = (new App)->addUserResponder(function() {
    return [
        $status = 200,
        $reason = 'OK',
        $headers = [
            'X-My-Header: some value',
            'Another-Header: some other value'
        ],
        $body = '<html><body><h1>ZOMG!!!1 PHP!!</h1></body></html>'
    ];
});
```

##### Asynchronous Responses

The most important thing to remember about Aerys (and indeed any server running inside a non-blocking
event loop) is that your application callables must not block execution with slow operations like
synchronous database access. Application callables may employ `yield` to act as a `Generator` and
cooperatively multitask with the server using non-blocking libraries.

```php
<?php
use Aerys\Framework\App;
require __DIR__ . '/path/to/aerys/autoload.php';

// in reality we'd use a non-blocking lib to do something here
function asyncMultiply($x, $y, callable $onCompletion) {
    $result = $x*$y;
    $onCompletion($result); // <-- array($result) is returned to our generator
}

// The key-value syntax assumes the async key accepts its fulfillment argument as the last parameter
function sexyAsyncResponder($asgiEnv) {
    $x = 6; $y = 7;
    list($result) = (yield 'asyncMultiply' => [$x, $y]);
    yield "<html><body><h1>Chicks dig brevity ({$result})!</h1></body></html>";
};

function uglyAsyncResponder($asgiEnv) {
    $x = 6; $y = 7;
    list($result) = (yield function(callable $onCompletion) use ($x, $y) {
        asyncMultiply($x, $y, $onCompletion);
    });
    yield "<html><body><h1>Ugly, but it works ({$result})!</h1></body></html>";
};

$myApp = (new App)
    ->setPort(1338)
    ->addRoute('GET', '/', 'sexyAsyncResponder')
    ->addRoute('GET', '/other', 'uglyAsyncResponder');
```

To demonstrate that we aren't limited to functions lets look at an example using instance methods.
Here we use a non-scalar callable key in our yield statement to execute a local property's method.
Note that *any* valid callable may be passed in the key portion of the `yield` statement:

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
        list($asyncResult) = (yield [$this->redis, 'get'] => 'mykey');
        yield $this->manipulateMyAsyncData($asyncResult);
    }
    private function manipulateMyAsyncData($asyncResult) {
        return "<html><body>{$asyncResult}</body></html>";
    }
}

$myApp = (new App)->addRoute('GET', '/', 'MyHandler::doSomething');
```

##### Named Hosts

Each `App` instance corresponds to a host on your server. Users may add as many host names as they
like for an individual server instance as shown below:

```php
<?php
use Aerys\Framework\App;
require __DIR__ . '/path/to/aerys/autoload.php';

// Our first host name
$mySite = (new App)
    ->setPort(80) // <-- Defaults to 80, so this isn't technically necessary
    ->setName('mysite.com')
    ->addUserResponder(function() {
        return '<html><body><h1>mysite.com</h1></body></html>';
    });

// Setup a subdomain
$myOtherHost = (new App)
    ->setName('subdomain.mysite.com')
    ->addUserResponder(function() {
        return '<html><body><h1>subdomain.mysite.com</h1></body></html>';
    });
```

##### Basic Routing

While you can add your own user responder callables, it's usually better to take advantage of
Aerys's builtin routing functionality to map URIs and HTTP method verbs to specific application
endpoints. Any valid callable or class instance method may be specified as a route target. Note that
class methods will have their instances automatically provisioned and injected affording routed
applications all the benefits of clean code and dependency injection.

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
    ->addRoute('GET', '/static', 'StaticClass::staticMethod')
    ->addRoute('GET', '/function', 'some_global_function')
    ->addRoute('GET', '/lambda', function() { return '<html><body>hello</body></html>'; })
    ->addRoute('GET', '/$#arg1/$#arg2/$arg3', 'SomeClass::routeArgs')
    ->setDocumentRoot('/path/to/static/files'); // <-- only if no routes match
```

##### Serving Static Files

Simply add the `App::setDocumentRoot` declaration to add high-performance static file serving to
host applications.

```php
<?php
use Aerys\Framework\App;
require __DIR__ . '/path/to/aerys/autoload.php';

// Any URI not matched by another handler is treated as a static file request
$myApp = (new App)
    ->addRoute('GET', '/', 'MyClass::myGetHandlerMethod')
    ->addRoute('POST', '/', 'MyClass::myPostHandlerMethod')
    ->setDocumentRoot('/path/to/static/file/root/');
```

##### TLS Encryption

Any host may utilize TLS encryption by passing appropriate settings to `App::setEncryption`. This
applies to *all* communications on the host (even websockets). The below example adds an additional
host on port 80 that redirects all traffic to the encrypted application on port 443.

```php
<?php
use Aerys\Framework\App;
require __DIR__ . '/path/to/aerys/autoload.php';

$encryptedApp = (new App)
    ->setPort(443)
    ->setEncryption([
        'local_cert' => __DIR__ . '/examples/support/tls_cert.pem',
        'passphrase' => '42 is not a legitimate passphrase',
        'ciphers'    => 'RC4-SHA:HIGH:!MD5:!aNULL:!EDH'
    ])->setDocumentRoot(__DIR__ . '/support/docroot');

// Because we can, let's redirect all unencrypted traffic on port 80 to port 443
$redirectApp = (new App)
    ->setPort(80)
    ->addUserResponder(function($asgiEnv) {
        $status = 302;
        $reason = 'Moved Temporarily';
        $headers = [
            'Location: https://127.0.0.1' . $asgiEnv['REQUEST_URI']
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

Aerys can also act as a reverse proxy and route certain requests through to backend servers. Using
this functionality we can do nifty things like layer websocket endpoints on top of an existing
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

##### Setting Server-Wide Options

Aerys servers offer many options that are not specific to individual host applications. To assign
these simply populate a `ServerOptions` instance in your configuration as demonstrated below.

```php
<?php
use Aerys\Framework\App, Aerys\Framework\ServerOptions;
require __DIR__ . '/path/to/aerys/autoload.php';

// Option keys are case-insensitive. These are some of the more useful options,
// but there are many more available ...
$options = (new ServerOptions)->setAll([
    'maxConnections'   => 2500, // # of simultaneously allowed clients per CPU core
    'maxRequests'      => 100,  // # of requests allowed on a single keep-alive connection
    'keepAliveTimeout' => 5,    // Seconds of inactivity before closing a connection
    'showErrors'       => FALSE // Don't show debug output in 500 response if your app errors
]);

$myApp = (new App)->addUserResponder(function() {
    return '<html><body><h1>OMG PHP is Webscale!</h1></body></html>';
});
```

## Dependencies

#### REQUIRED

- PHP 5.4+
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

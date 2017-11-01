---
title: HTTP APIs
permalink: /http
---
```php
return (new Aerys\Host)
    ->use(function(Aerys\Request $request, Aerys\Response $response) {
        $response->end("<h1>Hello, world!</h1>");
    });
```

To define a dynamic handler, all that is needed is a callable passed to `Host::use()`, accepting an `Aerys\Request` and an `Aerys\Response` instance as first and second parameters, respectively.

{:.note}
> This handler is used for all URIs of that Host by default, but it can be routed with the [Router](classes/router.md).

## Responses

```php
return (new Aerys\Host)->use(function(Aerys\Request $req, Aerys\Response $res) {
    # This is the default status code and does not need to be set explicitly
    # $res->setStatus(200);

    $res->setHeader("X-LIFE", "Very nice!");
    $res->end("With a bit text");
});
```

`Aerys\Response::setStatus($status)` sets the response status. The `$status` must be between 100 and 599. For reference see the [Wikipedia page on HTTP status codes](https://en.wikipedia.org/wiki/List_of_HTTP_status_codes).

`Aerys\Response::setHeader($header, $value)` sets a custom header, but be aware about header injections. Do not accept `\n` characters here if there will ever be user input! See also the [OWASP page on HTTP Response Splitting](https://www.owasp.org/index.php/HTTP_Response_Splitting).

`Aerys\Response::end($data = "")` terminates a response and sends the passed data. For more fine grained sending, have a look at [the guide about streaming](http-advanced.md#streaming-responses).

For a full explanation of all available methods check out the [`Response` class docs](classes/response.md).

## Requests

```php
return (new Aerys\Host)->use(function(Aerys\Request $req, Aerys\Response $res) {
    # if the header not passed, null is returned
    $user_agent = $req->getHeader("User-Agent") ?? "";

    # Get a query string parameter
    $action = $res->getParam("action") ?? "default";

    $res->end("Action: <i>$action</i> requested by User-Agent<br><pre>$user_agent</pre>");
});
```

Try accessing `http://localhost/?action=beautiful` in the browser.

`Aerys\Request::getParam(string $parameter)` returns a string if the query string parameter was passed (if multiple ones with the same name exist, the first one), otherwise `null`.

`Aerys\Request::getHeader(string $name)` returns a headers value. If multiple headers with the same name exist, it will return the first value. If none exists, it will return `null`.

There is additional information available about the full request API, check out the [`Request` docs](classes/request.md).

## Request Bodies

```php
return (new Aerys\Host)->use(function(Aerys\Request $req, Aerys\Response $res) {
    $body = yield Aerys\parseBody($req);
    $webserver = $body->get("webserver");

    if ($webserver === null) {
        $res->end('<form action="" method="post">Which one is the best webserver? <input type="text" name="webserver" /> <input type="submit" value="check" /></form>');
    } elseif (strtolower($webserver) == "aerys") {
        $res->end("Correct! Aerys is definitely the ultimate best webserver!");
    } else {
        $res->end("$webserver?? What's that? There is only Aerys!");
    }
});
```

`yield Aerys\parseBody($request, $size = 0)` expects an `Aerys\Request` instance and a maximum body size (there is [a configurable default](production.md)) as parameters and returns a [`ParsedBody`](classes/parsedbody.md) instance exposing a `get($name)` and a `getArray($name)`.

`get($name)` always returns a string (first parameter) or null if the parameter was not defined.

`getArray($name)` returns all the parameters with the same name in an array.

To get all the passed parameter names, use the `getNames()` method on the `ParsedBody` instance.

`getMetadata($name)` provides any metadata attached to a request parameter. There also is an `getMetadataArray($name)` function for an array with metadata of all parameters with the same name.

The metadata of a request consists of an array which may contain `"mime"` and `"filename"` keys if provided by the client (e.g. when uploading a file).

## Handling Uploads

```php
return (new Aerys\Host)->use(function(Aerys\Request $req, Aerys\Response $res) {
    $body = yield Aerys\parseBody($req, 200000 /* max 200 KB */);
    $file = $body->get("file");

    if ($file === null) {
        $res->end('<form action="" method="post">Upload a small avatar: <input type="file" name="file" /> <input type="submit" value="check" /></form>');
    } else {
        # in real world, you obviously need to first validate the filename against directory traversal and against overwriting...
        $name = $body->getMetadata("file")["filename"] ?? "<unnamed>";
        \Amp\file\put("files/$name", $file);
        $res->end("Got ".strlen($file)." bytes of data for a file named $name ... saved under files.");
    }
});
```

Generally uploads are just a normal field of the body you can grab with `get($name)`.

Additionally, uploads may contain some metadata: `getMetadata($name)` returns an array with the fields `"mime"` and `"filename"` (if the client passed these).

{:.warning}
> Avoid setting the `$size` parameter on `parseBody()` very high, that may impact performance with many users accessing it. Check [the guide for larger parsed bodies](performance.md#bodyparser) out if you want to do that.

## Cookies

```php
return (new Aerys\Host)->use(function (Aerys\Request $req, Aerys\Response $res) {
    if (($date = $req->getCookie('tasty')) !== null) {
        if ($req->getParam('eat') !== null) {
            $res->setCookie("tasty", "", ["Expires" => date("r", 784111777)]); # somewhen in the past
            $res->end("Mhhhhhhhm. A veeeery tasty cookie from ".date("d.m.Y H:i:s", (int) $date)."!<br />
                       No cookie there now ... <a href=\"?set\">GET A NEW ONE!</a> or <a href=\"/\">Go back.</a>'");
        } else {
            $res->end("A tasty cookie had been produced at ".date("d.m.Y H:i:s", (int) $date));
        }
    } elseif ($req->getParam('produce') !== null) {
        $res->setCookie("tasty", time(), ["HttpOnly"]);
        $res->end('A tasty cookie was produced right now. <a href="/">Go back.</a>');
    } else {
        $res->end('No cookie availables yet ... <a href="?produce">GET ONE RIGHT NOW!</a>');
    }
});
```

`Aerys\Request::getCookie($name)` returns a string with the value of the cookie of that $name, or `null`.

`Aerys\Response::setCookie($name, $value, $flags = [])` sets a cookie with a given name and value.

Valid flags are per [RFC 6265](https://tools.ietf.org/html/rfc6265#section-5.2.1):

- `"Expires" => date("r", $timestamp)` - A timestamp when the cookie will become invalid (set to a date in the past to delete it)
- `"Max-Age" => $seconds` - A number in seconds when the cookie must be expired by the client
- `"Domain" => $domain` - The domain where the cookie is available
- `"Path" => $path` - The path the cookie is restricted to
- `"Secure"` - Only send this cookie to the server over TLS
- `"HttpOnly"` - The client must hide this cookie from any scripts (e.g. Javascript)

## Static Routing

```php
$router = Aerys\router()
    ->route('GET', '/', function (Aerys\Request $req, Aerys\Response $res) {
        $csrf = bin2hex(random_bytes(32));
        $res->setCookie("csrf", $csrf);
        $res->end('<form action="form" method="POST" action="?csrf=$csrf"><input type="submit" value="1" name="typ" /> or <input type="submit" value="2" name="typ" /></form>');
    })
    ->route('POST', '/form', function (Aerys\Request $req, Aerys\Response $res) {
        $body = yield Aerys\parseBody($req);
        if ($body->getString("typ") == "2") {
            $res->end('2 is the absolutely right choice.');
        }
    }, function (Aerys\Request $req, Aerys\Response $res) {
        $res->setStatus(303);
        $res->setHeader("Location", "/form");
        $res->end(); # try removing this line to see why it is necessary
    })
    ->route('GET', '/form', function (Aerys\Request $req, Aerys\Response $res) {
        # if this route would not exist, we'd get a 405 Method Not Allowed
        $res->end('1 is a bad choice! Try again <a href="/">here</a>');
    });

return (new Aerys\Host)
    ->use(function(Aerys\Request $req, Aerys\Response $res) {
        if ($req->getMethod() == "POST" && $req->getCookie("csrf") != $req->getParam("csrf")) {
            $res->setStatus(400);
            $res->end("<h1>Bad Request</h1><p>Invalid csrf token!</p>");
        }
    })
    ->use($router)
    ->use(Aerys\root('/path/to/docroot'))
    ->use(function (Aerys\Request $req, Aerys\Response $res) {
        # if no response was started in the router (or no match found), we can have a custom 404 page here
        $res->setStatus(404);
        $res->end("<h1>Not found!</h1><p>There is no content at {$res->getUri()}</p>");
    });
```

A router is instantiated by `Aerys\router()`. To define routes: `->route($method, $location, $callable[, ...$callableOrMiddleware[, ...]])`, e.g. `->route('GET', '/foo', $callable)` or `->put('/foo', $callable, $middleware)`.

Alternate callables can be defined as fallback to have e.g. a static files handler or a custom `404 Not Found` page (precise: when no response was _started_ in the callable(s) before). Or even as a primary check for e.g. CSRF tokens to prevent execution of the main responder callable.

It is also possible to define routes with dynamic parts in them, see [the next step on dynamic route definitions](#dynamic-routing).

If there are more and more routes, there might be the desire to split them up. Refer to [the managing routes guide](http-advanced.md#managing-routes).

## Dynamic Routing

```php
$router = Aerys\router()
    ->route('GET', '/foo/?', function (Aerys\Request $req, Aerys\Request $res) {
        # This just works for trailing slashes
        $res->end("You got here by either requesting /foo or redirected here from /foo/ to /foo.");
    })
    ->route('GET', '/user/{name}/{id:[0-9]+}', function (Aerys\Request $req, Aerys\Response $res, array $route) {
        # matched by e.g. /user/rdlowrey/42
        # but not by /user/bwoebi/foo (note the regex requiring digits)
        $res->end("The user with name {$route['name']} and id {$route['id']} has been requested!");
    });

return (new Aerys\Host)->use($router);
```

The Router is using [FastRoute from Nikita Popov](https://github.com/nikic/FastRoute) and inherits its dynamic possibilities. Hence it is possible to use dynamic routes, the matches will be in a third $routes array passed to the callable. This array will contain the matches keyed by the identifiers in the route.

A trailing `/?` on the route will make the slash optional and, when the route is called with a slash, issue a 302 Temporary Redirect to the canonical route without trailing slash.

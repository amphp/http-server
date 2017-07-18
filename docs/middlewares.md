---
title: Middlewares
permalink: /middlewares
---
Middlewares can be `use()`'d via a `Host` instance or the `Router`.

They are able to manipulate responses as well as the request data before the application callable can read from them.

Even internal state of the connection can be altered by them. They have powers to break in deeply into the internals of the server.

{:.warning}
> Middlewares are technically able to directly break some assumptions of state by the server by altering certain values. Keep middlewares footprint as small as possible and only change what really is needed!

For example websockets are using a middleware to export the socket from within the server accessible via `InternalRequest->client->socket`.

Most middlewares though will only need to manipulate direct request input (headers) and operate on raw response output.

## Middleware::do

```php
return (new Aerys\Host)->use(new class implements Aerys\Middleware {
    function do(Aerys\InternalRequest $ireq) {
        $headers = yield;

        if (!$headers["x-capitalized"]) {
            # early abort
            return $headers;
        }

        $data = yield $headers;

        while ($data !== null) {
            # $data is false upon Response::flush()
            if ($data === false) {
                # we have to empty eventual buffers here, but as we don't buffer, no problem
            }

            $data = strtoupper(yield $data);
        }
    }

    # an application callable; the way you have middlewares and callables inside one single class
    function __invoke(Aerys\Request $req, Aerys\Response $res) {
        if (!$req->getParam('nocapitalize')) {
            $res->setHeader("X-CAPITALIZED", "0");
        } else {
            $res->setHeader("X-CAPITALIZED", "1");
        }

        # Middlewares will only receive headers here, upon the first write()/end()/flush() call
        $res->write("this ");
        $res->write("will ");
        $res->flush();
        $res->end("be CAPITALIZED!!!");
    }
});
```

`Middleware`s may return an instance of `Generator` in their `do()` method.

The first `yield` is always being sent in an array of headers in format `[$field => [$value, ...], ...]` (field names are lowercased).

Subsequent `yield`s will return either `false` (flush), a string (data) or `null` (end). [Note that `null` and `false` were chosen as casting them to string is resulting in an empty string.]

The first value `yield`ed by the middleware must be the array of headers.

Later `yield`s may return as much data as they want.

You can use `return` instead of `yield` in order to immediately detach with eventual final data. (In case you buffered a bit first and still hold the headers, use `return $data . yield $headers;`.)

{:.note}
> You cannot wait for promises inside middlewares. This is by design (as flushes should be propagating immediately etc.). If you need I/O, either move it to a responder in front of your actual responder or move it into your responder and call it explicitly.

## InternalRequest

```php
return (new Aerys\Host)
    ->use(new class implements Aerys\Middleware {
        # Middlewares don't have to return a Generator, they can also just terminate immediately
        function do(Aerys\InternalRequest $ireq) {
            // set maximum allowed body size generally for this host to 256 KB
            $ireq->maxBodySize = 256 * 1024; // 256 KB
            $ireq->client->httpDriver->upgradeBodySize($ireq);

            // define a random number
            $ireq->locals["tutorial.random"] = random_int(0, 19);
        }
    })
    ->use(function (Aerys\Request $req, Aerys\Response $res) {
        $res->write("This is the good number: " . $res->getLocalVar("tutorial.random") . "\n\n");
        // send the body contents back to the sender
        $res->end(yield $req->getBody());
    })
;
```

Middlewares provide access to [`InternalRequest`](classes/internalrequest.md). That class is a bunch of properties, [detailed here](classes/internalrequest.md).

These properties expose the internal request data as well as connection specific data via [`InternalRequest->client`](classes/client.md) property.

In particular, note the `InternalRequest->locals` property. There are the [`Request::getLocalVar($key)` respectively `Request::setLocalVar($key, $value)`](classes/request.md) methods which access or mutate that array. It is meant to be a general point of data exchange between the middlewares and the application callables.
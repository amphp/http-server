---
title: Advanced HTTP APIs
permalink: /http-advanced
---
## Streaming Responses

```php
$db = new Amp\Mysql\Pool("host=localhost;user=user;pass=pass;db=db");
return (new Aerys\Host)->use(function(Aerys\Request $req, Aerys\Response $res) use ($db) {
    $result = yield $db->prepare("SELECT data FROM table WHERE key = ?", [$req->getParam("key") ?? "default"]);
    while ($row = yield $result->fetchObject()) {
        $res->write($row->data);
        $res->write("\n");
        $res->flush();
    }
    $res->end(); # is implicit if streaming has been started, but useful to signal end of data to wait on other things now
});
```

`Response::write($data)` is an useful API to incrementally send data.

This does *not* guarantee that data is immediately sent; it may be buffered temporarily for performance or implementation reasons [example: the http driver may buffer up to Options->outputBufferSize bytes to reduce number of TCP frames].

There is a `Response::flush()` method which actually flushes all the buffers immediately.

## Pushing Resources

```php
return (new Aerys\Host)
    ->use(Aerys\root("/path/to/folder")) # contains image.png
    ->use(function(Aerys\Request $req, Aerys\Response $res) {
        $res->push("/image.png");
        $res->end('<html><body>A nice image:<br /><img src="/image.png" /></body></html>');
    })
;
```

`Response::push(string $uri, array $headers = null)` dispatches a push promise (with HTTP/2; with HTTP/1 only a `Link` header with a `preload` directive is sent).

Push promises are a powerful tool to reduce latencies and provide a better experience. When pushing, an internal request is dispatched just like it were requested by a client.

If the `$headers` parameter is `null`, certain headers are copied from the original request to match it as closely as possible.

## Managing Routes

```php
# This is the foo/router.php file
return Aerys\router()
    ->get("/", function(Aerys\Request $req, Aerys\Response $res) { $res->end("to-be-prefixed root"); })
    ->use(function(Aerys\Request $req, Aerys\Response $res) { $res->end("fallback route, only for this router"); }))
;
```

```php
$realRouter = Aerys\router()
    ->use((include "foo/router.php")->prefix("/foo"))
    ->get("/", function(Aerys\Request $req, Aerys\Response $res) { $res->end("real root"); })
    ->use(function(Aerys\Request $req, Aerys\Response $res) { $res->end("general fallback route"); }))
;

return (new Aerys\Host)->use($realRouter);
```

A `Router` can also `use()` `Bootable`s, `callable`s, `Middleware`s _and_ other `Router` instances.

This gives a certain flexibility allowing merging router definitions, easy definition of a common fallback callable or middleware for a group of routes.

For that purpose `Router::prefix($prefix)` exists, it allows to prefix all the routes with a certain `$prefix`.

That way, projects should provide a router file (no `Aerys\Host` instances, these are for the actual server definition!) containing all the routes and used `Middleware`s etc., so that the user can just eventually `prefix()` the `Router` instance easily in the big router and `use()` it in the `Host` instance.
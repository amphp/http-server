---
title: Functions
permalink: /classes/functions
---

* Table of Contents
{:toc}

## `router(array $options = []): Router`

Returns an instance of [`Router`](router.html).

There is currently only one option:

- `max_cache_entries`: number of cached routes (direct map of route-to-result)

## `websocket(Websocket|Bootable $app, array $options = []): Bootable`

Requires an instance of [`Websocket`](websocket.html) or an instance of [`Bootable`](bootable.html) returning an instance of [`Websocket`](websocket.html).

It wraps your [`Websocket`](websocket.html) implementing instance into something usable with [`Host::use()`](host.html) (or in [`Router`](router.html)).

## `root(string $docroot, array $options = []): Bootable`

Defines a static file root handler based on `$docroot`.

It returns an instance of something usable with [`Host::use()`](host.html) (or in [`Router`](router.html)).

Available `$options` are:

- `indexes`: An array of files serving as default files when a directory is requested (Default: `["index.html", "index.htm"]`)
- `useEtagInode`: Boolean whether inodes should be included in the etag (Default: `true`)
- `expiresPeriod`: TTL of client side cached files (`Expires` header) (Default: 7 days)
- `mimeFile`: Path to file containing mime types (Default: `etc/mime`)
- `mimeTypes`: Associative array of manually defined mime types in format `$extension => $mime`
- `defaultMimeType`: Mime type of files not having a mime type defined (Default: `text/plain`)
- `defaultTextCharset`: Default charset of text/ mime files (Default: `utf-8`)
- `useAggressiveCacheHeaders`: Boolean whether aggressive pre-check/post-check headers should be used
- `aggressiveCacheMultiplier`: Number between 0 and 1 when post-check will be active (Only relevant with `useAggressiveCacheHeaders` &mdash; Default: 0.9)
- `cacheEntryTtl`: TTL of in memory cache of file stat info (Default: 10 seconds)
- `cacheEntryMaxCount`: Maximum number of in memory file stat info cache entries (Default: 2048)
- `bufferedFileMaxCount`: Maximum number of in memory file content cache entries (Default: 50)
- `bufferedFileMaxSize`: Maximum size of a file to be cached (Default: 524288)

## `parseBody(Request $req, $size = 0): BodyParser`

Creates a [`BodyParser`](bodyparser.html) instance which can be `yield`ed to get the full body string, where `$size` is the maximum accepted body size.

## `parseCookie(string $cookies): array`

Parses a `Cookie` header string into an associative array of format `$name => $value`.

## `responseFilter(array $filters, InternalRequest $ireq): \Generator`

Returns a [middleware](middleware.html) Generator managing multiple filters. Can be `yield from` from another middleware or passed into the `responseCodec()` function.

## `responseCodec(\Generator $filter, InternalRequest $ireq): \Generator`

Returns a Generator which can be used to construct a `StandardResponse` object (its signature is `__construct(\Generator $codec, Client)` and implements [`Response`](response.html)).

This function may be useful for testing the combination of application callable and middlewares via a custom `InternalRequest->responseWriter.

## `init(\Psr\Log\LoggerInterface, array<Host>, array $options = []): Server`

This function is only useful, if you want to run Aerys as a small server within a bigger project, or have a specialized process manager etc., outside of the standard `bin/aerys` binary. For normal usage of Aerys it isn't needed.

It does a full initialization of all dependencies of [`Server`](server.md) and then returns an instance of `Server`, given only a PSR-3 logger, the individual [`Host`](host.md) instances and the server [`Options`](options.md).

The caller of this method then shall initialize the `Server` by calling `Server->start(): Promise`.

### Example

```php
\Amp\run(function() use ($logger /* any PSR-3 Logger */) {
    $handler = function(Aerys\Request $req, Aerys\Response $res) {
        $res->end("A highly specialized handler!");
    };
    $host = (new Aerys\Host)->use($handler);
    $server = Aerys\initServer($logger, [$host], ["debug" => true]);
    yield $server->start();
    # Aerys is running!
});
```

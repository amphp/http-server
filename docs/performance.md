---
title: Performance Tips
permalink: /performance
---
## Bottlenecks

Aerys in general is not a bottleneck. Misconfiguration, use of blocking I/O or inefficient applications are.

Aerys is well-optimized and can handle tens of thousands of requests per second on typical hardware while maintaining a high level of concurrency of thousands of clients.

But that performance will decrease drastically with inefficient applications. Aerys has the nice advantage of classes and handlers being always loaded, so there's no time lost with compilation and initialization.

A common trap is to begin operating on big data with simple string operations, requiring many inefficient big copies, which is why it is strongly recommended to use [incremental body parsing](#body) when processing larger incoming data, instead of processing the data all at once.

The problem really is CPU cost. Inefficient I/O management (as long as it is non-blocking!) is just delaying individual requests. It is recommended to dispatch simultaneously and eventually bundle multiple independent I/O requests via Amp combinators, but a slow handler will slow down every other request too. While one handler is computing, all the other handlers can't continue. Thus it is imperative to reduce computation times of the handlers to a minimum.

## Disconnecting Clients

```php
return (new Aerys\Host)->use(function(Aerys\Request $req, Aerys\Response $res) {
    $handle = \Amp\File\open("largefile.txt", "r");
    while (null !== $chunk = yield $handle->read(8192)) {
        yield $response->write($chunk); # it will just abort here, when the client disconnects
    }
});
```

`Response::write()` and `Websocket\Endpoint::send()` return a `Promise` which is fulfilled at the first moment where the buffers aren't full. That `Promise` may also fail with a `ClientException` if the clients write stream has been closed.

This allows to avoid spending too much processing time when fetching and returning large data incrementally as well as having too big buffers.

Thus, this isn't relevant for most handlers, except the ones possibly generating very much data (on the order of more than a few hundred kilobytes - the lowest size of the buffer typically is at least 64 KiB).

## Body

```php
use Amp\File;

return (new Aerys\Host)->use(function (Aerys\Request $req, Aerys\Response $res) {
    try {
        $path = "test.txt";
        $handle = yield File\open($path, "w+");
        $body = $res->getBody(10 * 1024 ** 2); // 10 MB
        
        while (null !== $data = yield $body->read()) {
            yield $handle->write($data);
        }
        
        $res->end("Data successfully saved");
    } catch (Aerys\ClientException $e) {
        # Writes may still arrive, even though reading stopped
        if ($e instanceof Aerys\ClientSizeException) {
            $res->end("Sent data too big, aborting");
        } else {
            $res->end("Data has not been recevied completely.");
        }
        
        yield $handle->close(); // explicit close to avoid issues when unlink()'ing
        yield File\unlink($path);
        
        throw $e;
    }
});
```

`Amp\ByteStream\Message` (and the equivalent `Aerys\Websocket\Message`) also provide incremental access to messages, which is particularly important if you have larger message limits (like tens of megabytes) and don't want to buffer it all in memory. If multiple people are uploading large bodies concurrently, the memory might quickly get exhausted.

Hence, incremental handling is important, accessible via [the `read()` API of `Amp\ByteStream\Message`](http://amphp.org/byte-stream/message).

In case a client disconnects, the `Message` instance fails with an `Aerys\ClientException`. This exception is thrown for both the `read()` API and when `yield`ing the `Message`. If the size limits are exceeded, it's a `ClientSizeException` which is a child class of `ClientException`.

{:.note}
> `ClientException`s do not *need* to be caught. You may catch them if you want to continue, but don't have to. The Server will silently end the request cycle and discard that exception then.

{:.note}
> This describes only the direct return value of `getBody($size = -1)` respectively the `Aerys\Websocket\Message` usage; there is [similar handling for parsed bodies](bodyparser.md).

## BodyParser

```php
(new Aerys\Host)->use(function(Aerys\Request $req, Aerys\Response $res) {
    try {
        $body = Aerys\parseBody($req);
        $field = $body->stream("field", 10 * 1024 ** 2); // 10 MB
        $name = (yield $field->getMetadata())["name"] ?? "<unknown>";
        $size = 0;
        while (null !== ($data = yield $field->valid())) {
            $size += \strlen($data));
        }
        $res->end("Received $size bytes for file $name");
    } catch (Aerys\ClientException $e) {
        # Writes may still arrive, even though reading stopped
        $res->end("Upload failed ...")
        throw $e;
    }
});
```

Apart from implementing `Amp\Promise` (to be able to return `Aerys\ParsedBody` upon `yield`), the `Aerys\BodyParser` class (an instance of which is returned by the `Aerys\parseBody()` function) exposes one additional method:

`stream($field, $size = 0): Aerys\FieldBody` with `$size` being the maximum size of the field (the size is added to the general size passed to `Aerys\parseBody()`).

This returned `Aerys\FieldBody` instance extends `\Amp\ByteStream\Message` and thus has [the same semantics](http://amphp.org/byte-stream/message).

Additionally, to provide the metadata information, the `Aerys\FieldBody` class has a `getMetadata()` function to return [the metadata array](http.md#request-body).

The `Aerys\BodyParser::stream()` function can be called multiple times on the same field name in order to fetch all the fields with the same name:

```php
# $body being an instance of Aerys\BodyParser
while (null !== $data = yield ($field = $body->stream("field"))->read()) {
    # init next entry of that name "field"
    do {
        # work on $data
    } while (null !== $data = yield $field->read());
}
```
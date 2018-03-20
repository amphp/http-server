---
title: Performance Tips
permalink: /performance
---
## Bottlenecks

The HTTP server won't be the bottleneck.
Misconfiguration, use of blocking I/O, or inefficient applications are.

The server is well-optimized and can handle tens of thousands of requests per second on typical hardware while maintaining a high level of concurrency of thousands of clients.

But that performance will decrease drastically with inefficient applications.
The server has the nice advantage of classes and handlers being always loaded, so there's no time lost with compilation and initialization.

A common trap is to begin operating on big data with simple string operations, requiring many inefficient big copies.
Instead, streaming should be used where possible for larger request and response bodies. 

The problem really is CPU cost.
Inefficient I/O management (as long as it is non-blocking!) is just delaying individual requests.
It is recommended to dispatch simultaneously and eventually bundle multiple independent I/O requests via Amp's combinators, but a slow handler will slow down every other request too.
While one handler is computing, all the other handlers can't continue.
Thus it is imperative to reduce computation times of the handlers to a minimum.

## Disconnecting Clients

_This section will discuss disconnecting clients with streamed response bodies._

{% include undocumented.md %}

## Body

`RequestBody` provides buffered and streamed access.
Use the streamed access to handle large messages, which is particularly important if you have larger message limits (like tens of megabytes) and don't want to buffer it all in memory.
If multiple people are uploading large bodies concurrently, the memory might quickly get exhausted.

Hence, incremental handling is important, accessible via [the `read()` API of `Amp\ByteStream\InputStream`](https://amphp.org/byte-stream/#inputstream).

In case a client disconnects, the `read()` fails with an `Amp\Http\Server\ClientException`.
This exception is thrown for both the `read()` and `buffer()` API.

{:.note}
> `ClientException`s do not *need* to be caught. You may catch them if you want to continue, but don't have to. The Server will silently end the request cycle and discard that exception then.

Instead of setting the generic body limit high, you should consider increasing the body limit only where needed, which is dynamically possible with the `increaseMaxSize()` method on `RequestBody`.

```php
<?php

use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\ByteStream;
use Amp\File;

$handler = new CallableRequestHandler(function (Request $request) {
    $path = "test.txt";
    
    try {
        /** @var File\Handle $file */
        $file = yield File\open($path, "w+");
        $body = $request->getBody();
        $body->increaseMaxSize(10 * 1024 ** 2); // 10 MB

        yield ByteStream\pipe($body, $file);

        return new Response(Status::OK, [], "OK, saved.");
    } catch (ClientException $e) {
        // Writes may still arrive, even though reading stopped
        if ($e->getCode() === Status::PAYLOAD_TOO_LARGE) {
            return new Response(Status::PAYLOAD_TOO_LARGE, [], "Too big, aborting.");
        }
        
        if (isset($file)) {
            // explicit close to avoid issues when unlink()'ing
            yield $file->close();
            yield File\unlink($path);
        }

        throw $e; // Don't care to return a response
    }
});
```

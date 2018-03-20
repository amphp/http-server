---
title: Handling I/O
permalink: /io
---
The HTTP server is built on top of [the non-blocking concurrency framework Amp](https://amphp.org/amp).
Thus it inherits full support of all its primitives and it is possible to use all the non-blocking libraries built on top of it.
That's also why several things deal with promises.

{:.note}
> In general, you should make yourself familiar with [the `Promise` **concept**](https://amphp.org/amp/promises), with [coroutines](https://amphp.org/amp/coroutines) and be aware of the several [combinator](https://amphp.org/amp/promises/combinators) and [coroutine helper](https://amphp.org/amp/coroutines/helpers) functions, to really succeed at using the HTTP server.

## Blocking I/O

Nearly every built-in function of PHP is doing blocking I/O, that means, the executing thread (mostly equivalent to the process in the case of PHP) will effectively be halted until the response is received.
A few examples of such functions: `mysqli_query`, `file_get_contents`, `usleep` and many more.

A good rule of thumb is: Every function doing I/O is doing it in a blocking way, unless you know for sure it doesn't.

Thus there are [libraries built on top of Amp](https://amphp.org/packages) providing implementations that work with non-blocking I/O. You should use these instead of the built-in functions.

{:.warning}
> Don't use any blocking I/O functions in the HTTP server.

```php
// Here's a bad example, DO NOT do something like that!

$handler = new CallableRequestHandler(function () {
    sleep(5); // Equivalent to a blocking I/O function with a 5 second timeout
    
    return new Response;
});

// Start a server with this handler and hit it twice.
// You'll have to wait until the 5 seconds are over until the second request is handled.
```

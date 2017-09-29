---
title: Handling I/O
permalink: /io
---
Aerys is built on top of [the non-blocking concurrency framework Amp](https://amphp.org/amp). Thus it inherits full support of all its primitives and it is possible to use all the non-blocking libraries built on top it. That's also why several things need to be `yield`ed, as they are `Promise`s. [Coroutines](https://amphp.org/amp/coroutines) let you await their resolution using `yield`, so you can write your code almost like blocking code. Most importantly, if the request or WebSocket handlers are returning a `Generator`, these are automatically run as coroutines.

{:.note}
> In general, you should make yourself familiar with [the Promise **concept**](https://amphp.org/amp/promises), with [`yield`ing](https://amphp.org/amp/coroutines) and be aware of the several [combinator](https://amphp.org/amp/promises/combinators) and [coroutine helper](https://amphp.org/amp/coroutines/helpers) functions, to really succeed at Aerys.

## Blocking I/O

Nearly every built-in function of PHP is doing blocking I/O, that means, the executing thread (equivalent to the process in the case of Aerys) will effectively be halted until the response is received. A few examples of such functions: `mysqli_query`, `file_get_contents`, `usleep` and many more.

A good rule of thumb is: Every function doing I/O is doing it in a blocking way, unless you know for sure it doesn't.

Thus there are [libraries built on top of Amp](https://amphp.org/packages) providing implementations that work with non-blocking I/O. You should use these instead of the built-in functions.

{:.warning}
> Don't use any blocking I/O functions in Aerys.

```php
// Here's a bad example, DO NOT do something like that!

return (new Aerys\Host)->use(function (Aerys\Request $req, Aerys\Response $res) {
    $res->end("Some data");
    sleep(5); // Equivalent to a blocking I/O function with a 5 second timeout
});

// Access this route twice. You'll have to wait until the 5 seconds are over until the second request is handled. Start Aerys with only one worker (`-w 1` / `-d`), otherwise your second request might be handled by another worker and the effect not be visible.
```

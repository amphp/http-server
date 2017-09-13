---
title: Handling I/O
permalink: /io
---

```php
return (new Aerys\Host)->use(function(Aerys\Request $req, Aerys\Response $res) {
    # in general yield is waiting for the Promise result to be available (just in the special case of Amp\Success it is available immediately)
    $data = yield new Amp\Success("foo"); # Amp\Success will always resolve to the value passed to its constructor
    $res->end($data); # We end up with $data === "foo"
});
```

Aerys is built on top of [the non-blocking concurrency framework Amp](http://amphp.org/amp).

Thus it inherits full support of all its primitives and it is possible to use all the non-blocking libraries built on top it.

That's also why several things need to be `yield`ed, as they are `Promise`s, which are resolved upon `yield` inside a Generator controlled by Amp. See also [the related documentation](http://amphp.org/amp/coroutines).

Most importantly, if the request handler callable or the WebSocket handlers are returning a Generator, these are also passed to Amp's control.

{:.note}
> In general, you should make yourself familiar with [the Promise **concept**](https://amphp.org/amp/promises), with [`yield`ing](https://amphp.org/amp/coroutines) and be aware of the several [combinator](https://amphp.org/amp/promises/combinators) and [coroutine helper](https://amphp.org/amp/coroutines/helpers) functions, to really succeed at Aerys.

## Blocking I/O

```php
# DO NOT DO THIS

return (new Aerys\Host)->use(function (Aerys\Request $req, Aerys\Response $res) {
    $res->end("Some data");
    sleep(5); # or a blocking I/O function with 5 second timeout
});

# Open this route twice, you'll have to wait until the 5 seconds are over, until the next request is handled. (To try, start Aerys with only one worker: -w 1)
```

{:.warning}
> DO NOT USE BLOCKING I/O FUNCTIONS IN AERYS!

Nearly every built-in function of PHP is doing blocking I/O, that means, the executing thread (equivalent to the process in the case of Aerys) will effectively be halted until the response is received. A few examples of such functions: `mysqli_query`, `file_get_contents`, `usleep` and many more.

A good rule of thumb is: Every function doing I/O is doing it in a blocking way, unless you know for sure it doesn't.

Thus there are [libraries built on top of Amp](http://amphp.org/packages) providing non-blocking I/O. You should use these instead of the built-in functions.

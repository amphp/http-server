---
title: I/O in Aerys
title_menu: Introduction
layout: tutorial
---

```php
(new Aerys\Host)->use(function(Aerys\Request $req, Aerys\Response $res) {
	# in general yield is waiting for the Promise result to be available (just in the special case of Amp\Success it is available immediately)
	$data = yield new Amp\Success("foo"); # Amp\Sucess will always resolve to the value passed to its constructor
	$res->end($data); # We end up with $data === "foo"
});
```

Aerys is built on top of [the non-blocking concurrency framework Amp](../../amp).

Thus it inherits full support of all its primitives and it is possible to use all the non-blocking libraries built on top it.

That's also why several things need to be `yield`ed, as they are `Promise`s, which are resolved upon `yield` inside a Generator controlled by Amp. See also [the related documentation](../../amp/coroutines/).

Most importantly, if the request handler callable or the Websocket handlers are returning a Generator, these are also passed to Amp's control.

> **Note**: In general, you should make yourself familiar with [the Promise **concept**](../../amp/promises/), with [`yield`ing](../../amp/coroutines/) and be aware of the several [combinator](../../amp/promises/helpers) and [coroutine helper](../../amp/coroutines/helpers) functions, to really succeed at Aerys.
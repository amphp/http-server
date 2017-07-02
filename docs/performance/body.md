---
title: Incremental body handling
title_menu: Incremental bodies
layout: tutorial
---
```php
(new Aerys\Host)->use(function (Aerys\Request $req, Aerys\Response $res) {
	try {
		$path = "test.txt";
		$handle = yield Amp\file\open($path, "w+");
		$body = $res->getBody(10 * 1024 ** 2); // 10 MB
		while (yield $body->valid()) {
			yield $handle->write($body->consume());
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
		yield Amp\file\unlink($path);
		throw $e;
	}
});
```

`Aerys\Body` (and the equivalent `Aerys\Websocket\Message`) also provide incremental access to messages, which is particularly important if you have larger message limits (like tens of Megabytes) and don't want to buffer it all in memory. If multiple people are uploading large bodies concurrently, the memory might quickly get exhausted.

Hence, incremental handling is important, accessible via [the `valid()` / `consume()` API of Amp\PromiseStream](../../amp) [@TODO bogus link, Amp\PromiseStream docs missing], which is inherited by `Aerys\Body`.

In case a client disconnects (or size limits are exceeded, then it's a `ClientSizeException`, a child class of `ClientException`) the `Aerys\Body` instance (which implements `Amp\Promise`) is failed with an `Aerys\ClientException`.

> **Note**: `ClientException`s do not *need* to be caught. You may catch them if you want to continue, but don't have to. The Server will silently end the request cycle and discard that exception then.

> **Note**: This describes only the direct return value of `getBody($size = -1)` respectively the `Aerys\Websocket\Message` usage; there is [similar handling for parsed bodies](bodyparser.html).
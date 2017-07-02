---
title: Writing Middlewares for Aerys
title_menu: Middleware::do()
layout: tutorial
---

```php
(new Aerys\Host)->use(new class implements Aerys\Middleware {
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

		# Middlewares will only receive headers here, upon the first stream()/end()/flush() call
		$res->stream("this ");
		$res->stream("will ");
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

> **Note**: You cannot wait for promises inside middlewares. This is by design (as flushes should be propagating immediately etc.). If you need I/O, either move it to a responder in front of your actual responder or move it into your responder and call it explicitly.

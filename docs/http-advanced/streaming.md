---
title: Incremental response sending via Response::write()
title_menu: Response::write()
layout: tutorial
---

```php
$db = new Amp\Mysql\Pool("host=localhost;user=user;pass=pass;db=db");
(new Aerys\Host)->use(function(Aerys\Request $req, Aerys\Response $res) use ($db) {
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

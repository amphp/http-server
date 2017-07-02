---
title: Pushing requests onto a client with Response::push()
title_menu: Response::push()
layout: tutorial
---

```php
(new Aerys\Host)
	->use(Aerys\root("/path/to/folder")) # contains image.png
	->use(function(Aerys\Request $req, Aerys\Response $res) {
		$res->push("/image.png");
		$res->end('<html><body>A nice image:<br /><img src="/image.png" /></body></html>');
	})
;
```

`Response::push(string $uri, array $headers = null)` dispatches a push promise (with HTTP/2; with HTTP/1 only a `Link` header with a `preload` directive is sent).

Push promises are a powerful tool to reduce latencies and provide a better experience. When pushing, an internal request is dispatched just like it were requested by a client.

If the `$headers` parameter is `null`, certain headers are copied from the original request to match it as closely as possible.
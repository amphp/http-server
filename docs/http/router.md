---
title: Static Routing in Aerys
description: Aerys is a non-blocking HTTP/1.1 and HTTP/2 application / websocket / static file server.
title_menu: Static Routing
layout: tutorial
---

```php
$router = Aerys\router()
	->get('/', function (Aerys\Request $req, Aerys\Response $res) {
		$csrf = bin2hex(random_bytes(32));
		$res->setCookie("csrf", $csrf);
		$res->end('<form action="form" method="POST" action="?csrf=$csrf"><input type="submit" value="1" name="typ" /> or <input type="submit" value="2" name="typ" /></form>');
	})
	->post('/form', function (Aerys\Request $req, Aerys\Response $res) {
		$body = yield Aerys\parseBody($req);
		if ($body->getString("typ") == "2") {
			$res->end('2 is the absolutely right choice.');
		}
	}, function (Aerys\Request $req, Aerys\Response $res) {
		$res->setStatus(303);
		$res->setHeader("Location", "/form");
		$res->end(); # try removing this line to see why it is necessary
	})
	->get('/form', function (Aerys\Request $req, Aerys\Response $res) {
		# if this route would not exist, we'd get a 405 Method Not Allowed
		$res->end('1 is a bad choice! Try again <a href="/">here</a>');
	});

(new Aerys\Host)
	->use(function(Aerys\Request $req, Aerys\Response $res) {
		if ($req->getMethod() == "POST" && $req->getCookie("csrf") != $req->getParam("csrf")) {
			$res->setStatus(400);
			$res->end("<h1>Bad Request</h1><p>Invalid csrf token!</p>");
		}
	})
	->use($router)
	->use(Aerys\root('/path/to/docroot'))
	->use(function (Aerys\Request $req, Aerys\Response $res) {
		# if no response was started in the router (or no match found), we can have a custom 404 page here
		$res->setStatus(404);
		$res->end("<h1>Not found!</h1><p>There is no content at {$res->getUri()}</p>");
	});
```

A router is instantiated by `Aerys\router()`. To define routes: `->method($location, $callable[, ...$callableOrMiddleware[, ...]])`, e.g. `->get('/foo', $callable)` or `->put('/foo', $callable, $middleware)`.

Alternate callables can be defined as fallback to have e.g. a static files handler or a custom 404 Not Found page (precise: when no response was _started_ in the callable(s) before). Or even as a primary check for e.g. csrf tokens to prevent execution of the main responder callable.

It is also possible to define routes with dynamic parts in them, see [the next step on dynamic route definitions](dynamic-routes.html).

If there are more and more routes, there might be the desire to split them up. Refer to [the managing routes guide](../http-advanced/routes.html).

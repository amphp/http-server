---
title: Dynamic Routing in Aerys
title_menu: Dynamic Routing
layout: tutorial
---

```php
$router = Aerys\router()
	->get('/foo/?', function (Aerys\Request $req, Aerys\Request $res) {
		# This just works for trailing slashes
		$res->end("You got here by either requesting /foo or redirected here from /foo/ to /foo.");
	})
	->get('/user/{name}/{id:[0-9]+}', function (Aerys\Request $req, Aerys\Response $res, array $route) {
		# matched by e.g. /user/rdlowrey/42
		# but not by /user/bwoebi/foo (note the regex requiring digits)
		$res->end("The user with name {$route['name']} and id {$route['id']} has been requested!");
	});

(new Aerys\Host)->use($router);
```

The Router is using [FastRoute from Nikita Popov](https://github.com/nikic/FastRoute) and inherits its dynamic possibilities. Hence it is possible to to use dynamic routes, the matches will be in a third $routes array passed to the callable. This array will contain the matches keyed by the identifiers in the route.

A trailing `/?` on the route will make the slash optional and, when the route is called with a slash, issue a 302 Temporary Redirect to the canonical route without trailing slash.

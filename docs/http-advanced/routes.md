---
title: Managing multiple routes
title_menu: Managing routes
layout: tutorial
---

```php
# This is the foo/router.php file
return Aerys\router()
	->get("/", function(Aerys\Request $req, Aerys\Response $res) { $res->end("to-be-prefixed root"); })
	->use(function(Aerys\Request $req, Aerys\Response $res) { $res->end("fallback route, only for this router"); }))
;
```

```php
$realRouter = Aerys\router()
	->use((include "foo/router.php")->prefix("/foo"))
	->get("/", function(Aerys\Request $req, Aerys\Response $res) { $res->end("real root"); })
	->use(function(Aerys\Request $req, Aerys\Response $res) { $res->end("general fallback route"); }))
;

(new Aerys\Host)->use($realRouter);
```

A `Router` can also `use()` `Bootable`s, `callable`s, `Middleware`s _and_ other `Router` instances.

This gives a certain flexibility allowing merging router definitions, easy definition of a common fallback callable or middleware for a group of routes.

For that purpose `Router::prefix($prefix)` exists, it allows to prefix all the routes with a certain `$prefix`.

That way, projects should provide a router file (no `Aerys\Host` instances, these are for the actual server definition!) containing all the routes and used `Middleware`s etc., so that the user can just eventually `prefix()` the `Router` instance easily in the big router and `use()` it in the `Host` instance.
---
title: Introduction to HTTP with Aerys
title_menu: Introduction
layout: tutorial
---

```php
(new Aerys\Host)->use(function(Aerys\Request $req, Aerys\Response $res) {
	$res->end("<h1>Hello, world!</h1>");
});
```

To define a dynamic handler, all that is needed is a callable passed to `Host::use()`, accepting an `Aerys\Request` and an `Aerys\Response` instance as first and second parameters, respectively.

> **Note**: This handler is used for all URIs of that Host by default, but it can be routed with the [Router](router.html).

---
title: Use()'ing on Aerys Hosts
title_menu: use()
layout: tutorial
---

```php
(new Aerys\Host)
	->use(Aerys\router()->get('/', function(Aerys\Request $req, Aerys\Response $res) { $res->end("default route"); }))
	->use(Aerys\root("/var/www/public_html")) # a file foo.txt exists in that folder
	->use(function(Aerys\Request $req, Aerys\Response $res) { $res->end("My 404!"); })
;
```

`Aerys\Host::use()` is the ubiquitous way to install handlers, `Middleware`s, `Bootable`s and the `HttpDriver`.

Handlers are executed in the order they are passed to `use()`, as long as no previous handler has started the response.

With the concrete example here:

- the path is `/`: the first handler is executed, and, as the route is matched, a response is initiated (`end()` or `stream()`), thus subsequent handlers are not executed.
- the path is `/foo.txt`: first handler is executed, but the response is not started (as no route starting a response was matched), then the second, which responds with the contents of the `foo.txt` file.
- the path is `/inexistent`: first and second handlers are executed, but they don't start a response, so the last handler is executed too, returning `My 404!`.

The execution order of `Middleware`s and `Bootable`s solely depends on the order they are passed to `use()` and are always all called. Refer to [the `Middleware`s guide](../middlewares/intro.html).

A custom `HttpDriver` instance can be only set once per port. It needs to be set on _all_ the `Host` instances bound on a same port. Refer to the [`HttpDriver` class docs](../contents/classes/httpdriver.html).
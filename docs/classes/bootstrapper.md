---
title: Bootstrapper in Aerys
title_menu: Bootstrapper
layout: docs
---

* Table of Contents
{:toc}

The `Bootstrapper` provides initialization routines for [`Server`](server.html).

This class is only useful, if you want to run Aerys as a small server within a bigger project, or have a specialized process manager etc., outside of the standard `bin/aerys` binary. For normal usage of Aerys it isn't needed.

There also is a `boot()` method, which is reading configuration from a file. It also calls the `init()` method. This `boot()` method is internal API; use `init()`.

## `__construct(callable():array $hostAggregator = null)`

By default, `Bootstrapper` reads from `Host::getDefinitions()`, which gives you a list of all `Host` instances ever created.

It is possible to specify here an alternative callable to return an array of `Host` instances.

## `init(\Psr\Log\LoggerInterface, array $options = []): Server`

This method does a full initialization of all dependencies of `Server` and then returns an instance of `Server`, given only a logger and the server [`Options`](options.html).

The caller of this method then shall initialize the `Server` by calling `Server->start(): Promise`.

## Example

```php
\Amp\run(function() use ($logger /* any PSR-3 Logger */) {
	$handler = function(Aerys\Request $req, Aerys\Response $res) {
		$res->end("A highly specialized handler!");
	};
	$bootstrapper = new Aerys\Bootstrapper(function () use ($handler) {
		return [(new Aerys\Host)->use($handler)];
	});
	$server = $bootstrapper->init($logger, ["debug" => true]);
	yield $server->start();
	# Aerys is running!
});
```

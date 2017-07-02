---
title: Bootables in Aerys
title_menu: Bootable
layout: docs
---

* Table of Contents
{:toc}

`Bootable`s provide a way to be injected the [`Server`](server.html) and [`\Psr\Log\LoggerInterface`](https://github.com/php-fig/log/blob/master/Psr/Log/LoggerInterface.php) (by default an instance of [`Logger`](logger.html)) instances on startup.

## `boot(Server, \Psr\Log\LoggerInterface): Middleware|callable|null`

This method is called exactly once when the [`Server`](server.html) is in `Server::STARTING` state.

You may return a [`Middleware`](middleware.html) and/or responder callable in order to use an alternate instance for middleware/responder.

> **Note**: It is a bad idea to rely on the order in which the `boot()` methods of the `Bootable`s are called

## Example

```php
class MyBootable implements Aerys\Bootable {
	function boot(Aerys\Server $server, Aerys\Logger $logger) {
		// we can now use $server in order to register a ServerObserver for example

		// In case we want to not use this instance for Middlewares or responder callables,
		// we can return an alternate one
		return new class implements Aerys\Middleware {
			function do(Aerys\InternalRequest $ireq) { /* ... */ }
		};
	}
}
(new Aerys\Host)->use(new MyBootable);
```

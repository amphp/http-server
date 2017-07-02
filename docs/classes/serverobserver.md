---
title: ServerObserver interface in Aerys
title_menu: ServerObserver
layout: docs
---

* Table of Contents
{:toc}

The `ServerObserver` interface is necessary to be able to watch for state changes of the [`Server`](server.html).

## `update(Server): Promise`

The only method of this interface; it is called each time when the [`Server`](server.html) changes its state. It is guaranteed that nothing further happens until all the `Promise`s returned by each attached `ServerObserver` have been resolved (or eventually timed out).

## Example

```php
class MyObserver implements Aerys\Bootable, Aerys\ServerObserver {
	function boot(Aerys\Server $server, Aerys\Logger $logger) {
		$server->attach($this);
	}

	function update(Aerys\Server $server): Amp\Promise {
		switch ($server->state()) {
			case Aerys\Server::STARTING: /* ... */ break;
			case Aerys\Server::STARTED: /* ... */ break;
			case Aerys\Server::STOPPING: /* ... */ break;
			case Aerys\Server::STOPPED: /* ... */ break;
		}
		return new Amp\Success;
	}
}
```

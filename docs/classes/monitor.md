---
title: Monitors in Aerys
title_menu: Monitor
layout: docs
---

* Table of Contents
{:toc}

`Monitor`s expose a method `monitor()` to retrieve statistical and sanity information about its internal state.

In particular the [`Server`](server.html) class extends `Monitor` and will call `monitor()` on every virtual host.

## `monitor(): array`

When invoked, this method must return an array with all the data it wishes to make available.

## Example

```php
class RequestCountingMonitor implements Aerys\Monitor, Aerys\Bootable {
	private $server;
	private $requestCounter = 0;

	function boot(Aerys\Server $server, \Psr\Log\LoggerInterface $log) {
		$this->server = $server;
	}

	function __invoke(Aerys\Request $req, Aerys\Response $res) {
		$this->requestCounter++;
		$res->write("<html><body><h1>MyMonitor</h1><ul>");
		foreach($server->monitor()["hosts"] as $id => $host) {
			$res->write("<li>$id: {$host[self::class][0]["requestCounter"]}</li>");
        }
        $res->end("</ul></body></html>")
	}

	function monitor(): array {
		return ["requestCounter" => $this->requestCounter];
	}
}
(new Aerys\Host)->name("foo.local")->use(new RequestCountingMonitor);
(new Aerys\Host)->name("bar.local")->use(new RequestCountingMonitor);
```

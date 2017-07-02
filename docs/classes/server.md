---
title: Server in Aerys
title_menu: Server
layout: docs
---

* Table of Contents
{:toc}

The `Server` instance controls the whole listening and dispatches the parsed requests.

## `attach(ServerObserver)`

Enables a [`ServerObserver`](serverobserver.html) instance to be notified of the updates.

## `detach(ServerObserver)`

Disables notifications for the passed [`ServerObserver`](serverobserver.html) instance.

## `state()`

Gets the current server state, which is one of the following class constants:

* `Server::STARTING`
* `Server::STARTED`
* `Server::STOPPING`
* `Server::STOPPED`

## `getOption(string)`

Gets an [`option`](options.html) value.

## `setOption(string, $value)`

Sets an [`option`](options.html) value.

## `stop(): Promise`

Initiate shutdown sequence. The returned `Promise` will resolve when the server has successfully been stopped.

## `monitor(): array`

See [`Monitor`](monitor.html), it returns an array of the following structure:

```php
[
	"state" => STARTING|STARTED|STOPPING|STOPPED,
	"bindings" => ["tcp://127.0.0.1:80", "tcp://ip:port", ...],
	"clients" => int, # number of clients
	"IPs" => int, # number of different connected IPs
	"pendingInputs" => int, # number of clients not being processed currently
	"hosts" => [
		"localhost:80" => [
			"interfaces" => [["127.0.0.1", 80], ["ip", port], ...],
			"name" => "localhost",
			"tls" => array with "ssl" context options,
			"handlers" => [
				"MyMonitorClass" => [
					MyMonitorClass->monitor(),
					MyMonitorClass->monitor(),
					... # if there are multiple instances of a same handler
				],
				"OtherMonitorClass" => [...],
				...
			],
		],
		"name:port" => [...],
		...
	],
]
```
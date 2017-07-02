---
title: CommandClient
permalink: /classes/commandclient
---

* Table of Contents
{:toc}

The `CommandClient` class provides an API (also externally usable by a custom control script for Aerys) for controlling an Aerys instance.

Command `Promise`s are resolved upon successful command transmission. They may fail with an `Exception` in case of server unavailability.

## `__construct(string $config)`

The constructor expects a config path of an Aerys instance.

## `restart(): Promise`

Restarts the Aerys instance. [This keeps the master process alive.]

## `stop(): Promise`

Stops the Aerys instance.

## Example

```php
# Stop the currently running server
# $server is an instance of Server
$command = new Aerys\CommandClient($server->getOption("configPath"));
yield $command->stop();
# Successfully stopped server!
```
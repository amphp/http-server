---
title: Server
permalink: /classes/server
---

* Table of Contents
{:toc}

The `Server` instance controls the whole listening and dispatches the parsed requests.

The `Server` class has a [custom debug output](http://php.net/manual/en/language.oop5.magic.php#object.debuginfo), which isn't part of the public API and which shouldn't be relied upon.

## Constructor

```php
public function __construct(
    Amp\Socket\Server[] $servers,
    RequestHandler $requestHandler,
    Psr\Log\LoggerInterface $logger,
    Options $options = null
)
```

### Parameters

|[`Amp\Socket\Server[]`](https://amphp.org/socket/server)|`$servers`|List of socket servers.|
|[`RequestHandler`](request-handler.md)|`$requestHandler`|Request handler interface.|
|[`Psr\Log\LoggerInterface`]()|`$logger`|A PSR compliant logger (eg. [amphp/log](https://github.com/amphp/log)).|
|[`Options`](options.md)<br />`null`|`$options`|HTTP server settings.<br />`null` creates an `Options` object with all default options.|

{:.warning}
> `$servers` must be a non-empty list of [`Amp\Socket\Server`](https://amphp.org/socket/server) objects. Otherwise an [`\Error`](http://php.net/manual/en/class.error.php) will be thrown.

## `setDriverFactory(HttpDriverFactory $driverFactory): void`

Define a custom HTTP driver factory.

## `setClientFactory(ClientFactory $clientFactory): void`

Define a custom client factory.

## `setErrorHandler(ErrorHandler $errorHandler): void`

Sets the error handler instance to be used for generating error responses.

## `getState(): int`

Returns the current server state, which is one of the following class constants:

* `Server::STARTING`
* `Server::STARTED`
* `Server::STOPPING`
* `Server::STOPPED`

## `getOptions(): Options`

Returns the server options object.

## `getErrorHandler(): ErrorHandler`

Returns the error handler.

## `getLogger(): Psr\Log\LoggerInterface`

Returns the logger.

## `getTimeReference(): TimeReference`

Returns the time context.

## `attach(ServerObserver $observer)`

Enables a [`ServerObserver`](server-observer.md) instance to be notified of the updates.

## `start(): Promise`

Starts the server.

## `stop(int $timeout = 3000ms): Promise`

Stops the server. `$timeout` is the number of milliseconds to allow clients to gracefully shutdown before forcefully closing.

The returned `Promise` will resolve when the server has successfully been stopped.

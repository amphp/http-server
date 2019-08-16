---
title: HttpDriver
permalink: /classes/http-driver
---

* Table of Contents
{:toc}

`HttpDriver` is an interface for handling the raw input received from the client socket and writing the raw output back to the client.

Each connected client uses a separate instance of `HttpDriver`, created by an instance of `HttpDriverFactory`. This instance may be set using `Server::setHttpDriverFactory()`. By default, `DefaultHttpDriverFactory` is used.

There are currently two classes implementing `HttpDriver`: `Http1Driver` (for HTTP/1.0 and HTTP/1.1) and `Http2Driver` (for HTTP/2).

## `setup(Client $client, callable $onMessage, callable $write): \Generator`

This method is called only once, immediately after the `HttpDriver` object is returned from the `HttpDriverFactory::selectDriver()` method.

`$client` is the connected `Client` instance.

`$onMessage` is a `callable(Request $request): Promise` accepting an instance of `Request`, returning a `Promise` that is resolved once a response has been generated and the writing of the response is initialized by calling `HttpDriver::write()`. Note that writing the response is not necessarily complete when the promise resolves.

`$write` is a `callable(string $data, bool $close = false): Promise` that is used to write raw bytes in `$data` to the client. If `$close` is `true`, the client is closed after the bytes have been written.

This method returns a generator that is sent the raw data read from the client. The generator yields `null` or `Promise` instances. If the generator yields a promise, no additional data is to be sent to the parser until the promise resolves. Only null yields will receive additional client data. Promise yields will be sent `null`. The generator should not throw an exception.

## `write(Request $request, Response $response): Promise`

Writes the response to the client. The returned promise is resolved once the entire response has been written.

## `getPendingRequestCount(): int`

Returns the number of request bodies currently being read by the parser.

## `stop(): Promise`

This method signals the driver should stop dispatching further requests from the parser. The returned promise is resolved once all currently pending requests have been fully read and replied to.

---
title: Client
permalink: /classes/client
---
`Amp\Http\Server\Driver\Client` bundles all client-related details, such as the connection and used [`HttpDriver`](http-driver.md).
A default implementation can be found in `Amp\Http\Server\Driver\RemoteClient`.

* Table of Contents
{:toc}

## `start(HttpDriverFactory $factory)`

Listen for requests on the client and parse them using the HTTP driver created by the given driver factory. This method is called by the server and must not be called again.

## `getOptions(): Options`

Server options object.

## `getPendingRequestCount(): int`

Number of requests being read.

## `getPendingResponseCount(): int`

Number of requests with pending responses.

## `isWaitingOnResponse(): bool`

`true` if the number of pending responses is greater than the number of pending requests.
Useful for determining if a request handler is actively writing a response or if a request is taking too long to arrive.

## `getId(): int`

Integer ID of this client.
This ID is unique per process, see [PHP.net documentation for resource casts to integers](https://secure.php.net/manual/en/language.types.integer.php#language.types.integer.casting).

## `getRemoteAddress(): SocketAddress`

Remote address or unix socket path.

## `getLocalAddress(): SocketAddress`

Local server address or unix socket path.

## `isEncrypted(): bool`

`true` if the client is encrypted, `false` if plaintext.

## `getTlsInfo(): ?TlsInfo`

If the client is encrypted, returns the `TlsInfo` object. Null is returned for plaintext clients.

## `isExported(): bool`

`true` if the client has been exported from the server using `Response::detach()`.

## `getStatus(): int`

Integer mask of `Client::CLOSED_*` constants.

## `onClose(callable $onClose): void`

Attaches a callback invoked with this client closes.
The callback is passed this object as the first parameter.

## `close(): void`

Forcefully closes the client connection.

---
title: Client
permalink: /classes/client
---
`Amp\Http\Server\Driver\Client` bundles all client-related details, such as the connection and used [`HttpDriver`](http-driver.md).
A default implementation can be found in `Amp\Http\Server\Driver\RemoteClient`.

* Table of Contents
{:toc}

## `start(HttpDriverFactory $factory)`

Listen for requests on the client and parse them using the given HTTP driver.

## `getOptions(): Options`

Server options object.

## `getPendingRequestCount()`

Number of requests being read.

## `getPendingResponseCount()`

Number of requests with pending responses.

## `isWaitingOnResponse()`

`true` if the number of pending responses is greater than the number of pending requests.
Useful for determining if a request handler is actively writing a response or if a request is taking too long to arrive.

## `getId()`

Integer ID of this client.
This ID is unique per process, see [PHP.net documentation for resource casts to integers](https://secure.php.net/manual/en/language.types.integer.php#language.types.integer.casting).

## `getRemoteAddress(): string`

Remote IP address or unix socket path.

## `getRemotePort(): ?int`

Remote port number or `null` for unix sockets.

## `getLocalAddress(): string`

Local server IP address or unix socket path.

## `getLocalPort(): ?int`

Local server port or `null` for unix sockets.

## `isUnix(): bool`

`true` if this client is connected via an unix domain socket.

## `isEncrypted(): bool`

`true` if the client is encrypted, `false` if plaintext.

## `getCryptoContext(): array`

If the client is encrypted, returns the array returned from `stream_get_meta_data($this->socket)["crypto"]`.
Otherwise returns an empty array.

## `isExported(): bool`

`true` if the client has been exported from the server using `Response::detach()`.

## `getStatus(): int`

Integer mask of `Client::CLOSED_*` constants.

## `onClose(callable $onClose)`

Attaches a callback invoked with this client closes.
The callback is passed this object as the first parameter.

## `close()`

Forcefully closes the client connection.

---
title: HttpDriver
permalink: /classes/http-driver
---

* Table of Contents
{:toc}

`HttpDriver` is an interface managing the raw input from and to the client socket directly.

It is only possible to have one driver per **port**. There are some few possible applications of it (e.g. a PROXY protocol wrapping HTTP/1.1 communication). There are currently two classes implementing it `Http1Driver` (for HTTP/1) and `Http2Driver` (for HTTP/2).

## `setup(array $parseEmitters, callable $responseWriter)`

Called upon initialization (possibly even before [`Bootable::boot`](bootable.md) was called).

`$parseEmitter` is an array of `callable`s, keyed by `HttpDriver` constants.

When an instance of `InternalRequest` is passed, in particular `client`, `headers`, `method`, `protocol`, `trace` and `uri*` properties should be initialized.

Several callables have an optional `$streamId` parameter. If only one request is handled simultaneously on a same connection, this parameter can be ignored, otherwise one must set it to the same value than `InternalRequest->streamId` to enable the server to feed the body data to the right request.

Depending on the callback type, different signatures are expected:

- `HttpDriver::RESULT`: the request has no entity body. Expects an `InternalRequest` as only parameter.
- `HttpDriver::ENTITY_HEADERS`: the request will be followed by subsequent body (`HttpDriver::ENTITY_PART` / `HttpDriver::ENITITY_RESULT`). Expects `InternalRequest` as only parameter.
- `HttpDriver::ENTITY_PART`: contains the next part of the entity body. The signature is `(Client, string $body, int $streamId = 0)`
- `HttpDriver::ENTITY_RESULT`: signals the end of the entity body. The signature is `(Client, int $streamId = 0)`.
- `HttpDriver::SIZE_WARNING`: to be used when the body size exceeds the current size limits (by default `Options->maxBodySize`, might have been upgraded via `upgradeBodySize()`). Before emitting this, all the data up to the limit **must** be emitted via `HttpDriver::ENTITY_PART` first. The signature is `(Client, int $streamId = 0)`.
- `HttpDriver::ERROR`: signals a protocol error. Here are two additional trailing arguments to this callback: a HTTP status code followed by a string error message. The signature is `(Client, int $status, string $message)`.

`$responseWriter` is a `callable(Client $client, bool $final = false)`, supposed to be called after updates to [`$client->writeBuffer`](client.md) with the `$final` parameter signaling the response end [this is important for managing timeouts and counters].

## `filters(InternalRequest $ireq, array<callable> $userFilters): array<callable>`

Returns an array of callables working according to the [`Filter`](filter.md) protocol. [Not actual `Filter` instances, but only the direct `callable`!]

## `writer(InternalRequest $ireq): \Generator`

The Generator is receiving with the first `yield` an array with the headers containing a map of field name to array of string values (pseudo-headers starting with a colon do not map to an array, but directly to a value).

Subsequent `yield`s are string data with eventual intermittent `false` to signal flushing.

Then a final `null` will be returned by `yield`.

## `upgradeBodySize(InternalRequest $ireq)`

May be called any time the body size limits wish to be increased.

It should take the necessary measures so that further `HttpDriver::ENTITY_PART` may be sent.

## `parser(Client $client): \Generator`

Inside the parser `yield` always returns raw string data from the socket.

{:.note}
> You _can_ rely on keep-alive timeout terminating the `\Amp\ByteStream\Message` with a `ClientException`, when no further data comes in. No need to manually handle that here.

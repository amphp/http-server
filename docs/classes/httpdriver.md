---
title: HttpDriver in Aerys
title_menu: HttpDriver
layout: docs
---

* Table of Contents
{:toc}

`HttpDriver` is an interface managing the raw input from and to the client socket directly.

It is only possible to have one driver per **port**. There are some few possible applications of it (e.g. a PROXY protocol wrapping HTTP/1.1 communication). There are currently two classes implementing it `Http1Driver` (for HTTP/1) and `Http2Driver` (for HTTP/2).

## `setup(callable $parseEmitter, callable $responseWriter)`

Called upon initialization (possibly even before [`Bootable::boot`](bootable.html) was called).

`$parseEmitter` is a `callable(Client $client, int $eventType, array $parseResult, string $errorStruct = null)`.

In every case the `$parseResult` array needs to contain an `"id"` key with an identifier (unique during the requests lifetime) for the specific request.

A _request initializing_ `$parseResult` array requires a `"trace"` key (raw string trace or array with `[field, value]` header pairs), with optional `"uri"` (string &mdash; default: `"/"`), `"method"` (string &mdash; default: `"GET"`), `"protocol"` (string &mdash; default: `"1.0"`) and `"headers"` (array with `[field, value]` pairs &mdash; default: `[]`).

Depending on `$eventType` value, different `$parseResult` contents are expected:

- `HttpDriver::RESULT`: the request has no entity body and `$parseResult` must be _request initializing_.
- `HttpDriver::ENTITY_HEADERS`: the request will be followed by subsequent body (`HttpDriver::ENTITY_PART` / `HttpDriver::ENITITY_RESULT`) and `$parseResult` must be _request initializing_.
- `HttpDriver::ENTITY_PART`: contains the next part of the entity body, inside a `"body"` key inside the `$parseResult` array.
- `HttpDriver::ENTITY_RESULT`: signals the end of the entity body
- `HttpDriver::ERROR`: `$parseResult` must be _request initializing_ if the Request has not started yet; `$errorStruct` shall contain a short string message to generate the error.
- `HttpDriver::SIZE_WARNING`: to be used when the body size exceeds the current size limits (by default `Options->maxBodySize`, might have been upgraded via `upgradeBodySize()`). Before emitting this, all the data up to the limit **must** be emitted via `HttpDriver::ENTITY_PART` first.

`$responseWriter` is a `callable(Client $client, bool $final = false)`, supposed to be called after updates to [`$client->writeBuffer`](client.html) with the `$final` parameter signaling the response end [this is important for managing timeouts and counters].

## `filters(InternalRequest $ireq, array<callable> $userFilters): array<callable>`

Returns an array of callables working according to the [`Middleware`](middleware.html) protocol. [Not actual `Middleware` instances, but only the direct `callable`!]

## `writer(InternalRequest $ireq): \Generator`

The Generator is receiving with the first `yield` an array with the headers containing a map of field name to array of string values (pseudo-headers starting with a colon do not map to an array, but directly to a value).

Subsequent `yield`s are string data with eventual intermittent `false` to signal flushing.

Then a final `null` will be returned by `yield`.

## `upgradeBodySize(InternalRequest $ireq)`

May be called any time the body size limits wish to be increased.

It should take the necessary measures so that further `HttpDriver::ENTITY_PART` may be sent.

## `parser(Client $client): \Generator`

Inside the parser `yield` always returns raw string data from the socket.

> **Note**: You _can_ rely on keep-alive timeout terminating the `\Amp\ByteStream\Message` with a `ClientException`, when no further data comes in. No need to manually handle that here.

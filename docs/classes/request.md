---
title: Request
permalink: /classes/request
---

* Table of Contents
{:toc}

The `Request` interface generally finds its only use in responder callables (or [`Websocket::onOpen()`](websocket.html#onopen)). [`Middleware`s](middleware.md) do never see the `Request`; the `StandardRequest` class is supposed to be a simple request API reading from and manipulating [`InternalRequest`](internalrequest.md) under the hood.

## `getMethod(): string`

Returns the used method, e.g. `"GET"`.

## `getUri(): string`

Returns the requested URI (path and query string), e.g. `"/foo?bar"`.

## `getProtocolVersion(): string`

Currently it will return one of the three supported versions: `"1.0"`, `"1.1"` or `"2.0"`.

## `getHeader(string): string | null`

Gets the first value of all the headers with that name.

## `getHeaderArray(string): array<string>`

Gets an array with headers. HTTP allows for multiple headers with the same name, so this returns an array. Usually only a single header is needed and expected, in this case there is `getHeader()`.

## `getAllHeaders(): array<array<string>>`

Returns all the headers in an associative map with the keys being normalized header names in lowercase.

## `getParam(string): string | null`

Gets the first value of all the query string parameters with that name.

## `getParamArray(string): array<string>`

Gets an array with the values of the query string parameters with that name.

## `getAllParams(): array<array<string>>`

Gets the decoded query string as associative array.

## `getBody(): \Amp\ByteStream\Message`

Returns a representation of the request body. The [`\Amp\ByteStream\Message`](//byte-stream/message) can be `yield`ed to get the actual string.

There also exists a [`parseBody()`](parsedbody.md) function for processing of a typical HTTP form data.

## `getCookie(string): string | null`

Gets a cookie value by name.

## `getConnectionInfo(): array`

Returns various information about the request, a map of the array is:

```php
[
    "client_port"  => int,
    "client_addr"  => string,
    "server_port"  => int,
    "server_addr"  => string,
    "is_encrypted" => bool,
    "crypto_info"  => array, # Like returned via stream_get_meta_data($socket)["crypto"]
]
```

## `getLocalVar(string)` / `setLocalVar(string, $value)`

These methods are only important when using [`Middleware`s](middleware.md). They manipulate the [`InternalRequest->locals`](internalrequest.html#locals) array.

## `getOption(string)`

Gets an [option](options.md) value.

## `StandardRequest::__construct(InternalRequest)`

The constructor accepts an [`InternalRequest`](internalrequest.md) object the `StandardRequest` class is reading and writing to.

{:.note}
> It may be helpful in integration tests to provide a `StandardRequest` class initialized with an adequately preset `InternalRequest` object.

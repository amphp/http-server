---
title: Response
permalink: /classes/response
---

* Table of Contents
{:toc}

The `Response` interface (extends `\Amp\ByteStream\OutputStream`) generally finds its only use in responder callables (or [`Websocket::onOpen()`](websocket.md#onopenint-clientid-handshakedata)). [`Middleware`s](middleware.md) do never see the `Response`; the `StandardResponse` class is communicating headers, data and flushes to a Generator under the hood.

## `setStatus(int $code): Response`

Sets the numeric HTTP status code (between 100 and 599).

If not assigned this value defaults to 200.

## `setReason(string $phrase): Response`

Sets the optional HTTP reason phrase.

## `addHeader(string $field, string $value): Response`

Appends the specified header.

## `setHeader(string $field, string $value): Response`

Sets the specified header.

This method will replace any existing headers for the specified field.

## `setCookie(string $name, string $value, array $flags = []): Response`

Provides an easy API to set cookie headers.

Those who prefer using addHeader() may do so.

Valid `$flags` are per [RFC 6265](https://tools.ietf.org/html/rfc6265#section-5.2.1):

- `"Expires" => date("r", $timestamp)` - A timestamp when the cookie will become invalid (set to a date in the past to delete it)
- `"Max-Age" => $seconds` - A number in seconds when the cookie must be expired by the client
- `"Domain" => $domain` - The domain where the cookie is available
- `"Path" => $path` - The path the cookie is restricted to
- `"Secure"` - Only send this cookie to the server over TLS
- `"HttpOnly"` - The client must hide this cookie from any scripts (e.g. Javascript)

## `write(string $partialBodyChunk): \Amp\Promise`

Incrementally streams parts of the response body.

Applications that can afford to buffer an entire response in memory or can wait for all body data to generate may use `Response::end()` to output the entire response in a single call.

{:.note}
> Headers are sent upon the first invocation of Response::write().

## `flush()`

Forces a flush message [`false` inside `Middleware`s and `HttpDriver`] to be propagated and any buffers forwarded to the client.

Calling this method only makes sense when streaming output via `Response::write()`. Invoking it before calling `write()` or after `end()` is a logic error.

## `end(string $finalBodyChunk = null)`

End any streaming response output with an optional final message by `$finalBodyChunk`.

User applications are **not** required to call `Response::end()` after streaming or sending response data (though it's not incorrect to do so) &mdash; the server will automatically call `end()` as needed.

Passing the optional `$finalBodyChunk` parameter is a shortcut equivalent to
the following:

    $response->write($finalBodyChunk);
    $response->end();

{:.note}
> Thus it is also fine to call this function without previous `write()` calls, to send it all at once.

## `push(string $url, array $headers = null): Response`

Indicate resources which a client very likely needs to fetch. (e.g. `Link: preload` header or HTTP/2 Push Promises)

If a push promise is actually being sent, it will be dispatched with the `$headers` if not `null`, else the server will try to reuse some headers from the  request

## `state(): int`

Retrieves the current response state

The response state is a bitmask of the following flags:

 - `Response::NONE`
 - `Response::STARTED`
 - `Response::STREAMING`
 - `Response::ENDED`

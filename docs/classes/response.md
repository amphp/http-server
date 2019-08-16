---
title: Response
permalink: /classes/response
---

* Table of Contents
{:toc}

The **`Response`** class represents an HTTP response. The **`Response`** promise is returned by [request handlers](request-handler.md) and [middleware](middleware.md).

## Constructor

```php
public function __construct(
    int $code = Status::OK, 
    string[] | string[][] $headers = [], 
    Amp\ByteStream\InputStream | string | null $stringOrStream = null,
    ?Trailers $trailers = null
)
```

### Parameters

|`int`|`$code`|HTTP response status code|
|`string[]`<br />`string[][]`|`$headers`|An array of strings or an array of string arrays.|
|[`Amp\ByteStream\InputStream`](https://amphp.org/byte-stream/)<br />`string`<br />`null`|`$stringOrStream`|Response body|

## Destructor

Invokes dispose handlers (i.e. functions that registered via [`onDispose`](#ondisposecallable-ondispose) method).

{:.note}
> Uncaught exceptions from the dispose handlers will be forwarded to the [event loop](https://amphp.org/amp/event-loop/) error handler.

## `getBody(): Amp\ByteStream\InputStream`

Returns the [stream](https://amphp.org/byte-stream/) for the message body.

## `setBody(\Amp\ByteStream\InputStream | string | null $stringOrStream)`

Sets the [stream](https://amphp.org/byte-stream/) for the message body.

{:.note}
> Using a string will automatically set the `Content-Length` header to the length of the given string.
> Setting an [`InputStream`](https://amphp.org/byte-stream/#inputstream) will remove the `Content-Length` header.
> If you know the exact content length of your stream, you can add a `content-length` header _after_ calling `setBody()`.

## `getHeaders(): string[][]`

Returns the headers as a string-indexed array of arrays of strings or an empty array if no headers have been set.

## `hasHeader(string $name): bool`

Checks if given header exists.

## `getHeaderArray(string $name): string[]`

Returns the array of values for the given header or an empty array if the header does not exist.

## `getHeader(string $name): ?string`

Returns the value of the given header.
If multiple headers are present for the named header, only the first header value will be returned.
Use `getHeaderArray()` to return an array of all values for the particular header.
Returns `null` if the header does not exist.

## `setHeaders(array $headers): void`

Sets the headers from the given array.

## `setHeader(string $name, string | string[] $value): void`

Sets the header to the given value(s).
All previous header lines with the given name will be replaced.

## `addHeader(string $name, string | string[] $value): void`

Adds an additional header line with the given name.

## `removeHeader(string $name): void`

Removes the given header if it exists.
If multiple header lines with the same name exist, all of them are removed.

## `getStatus(): int`

Returns the response status code.

## `getReason(): string`

Returns the reason phrase describing the status code.

## `setStatus(int $code, string | null $reason): void`

Sets the numeric HTTP status code (between 100 and 599) and reason phrase. Use null for the reason phrase to use the default phrase associated with the status code.

## `getCookies(): ResponseCookie[]`

Returns all [cookies](https://amphp.org/http/cookies) in an associative map.

## `getCookie(string $name): ?ResponseCookie`

Gets a [cookie](https://amphp.org/http/cookies) value by name or `null` if no cookie with that name is present.

## `setCookie(ResponseCookie $cookie): void`

Adds a [cookie](https://amphp.org/http/cookies) to the response.

## `removeCookie(string $name): void`

Removes a [cookie](https://amphp.org/http/cookies) from the response.

## `getPush(): string[][]`

Returns list of push resources in an associative map:

```php
[
    string $url => [ Psr\Http\Message\UriInterface $uri, string[][] $headers ],
]
```

## `push(string $url, string[][] $headers): void`

Indicate resources which a client likely needs to fetch. (e.g. `Link: preload` or HTTP/2 Server Push).

## `isUpgraded(): bool`

Returns `true` if a detach callback has been set, `false` if none.

## `upgrade(callable $upgrade): void`

Sets a callback to be invoked once the response has been written to the client and changes the status of the response to `101 Switching Protocols`. The callback receives an instance of `Driver\UpgradedSocket` as the only argument. The callback may return a Generator which will be run as a coroutine. Throwing an exception from the callback disconnects the client and writes the exception message to the server log.

The callback may be removed by changing the status to something else.

## `getUpgradeCallable(): ?callable`

Returns the upgrade function if present.

## `onDispose(callable $onDispose): void`

Registers a function that is invoked when the Response is discarded. A response is discarded either once it has been written to the client or if it gets replaced in a middleware chain.

## `getTrailers(): Trailers`

Allows access to the [`Trailers`](trailers.md) of a response.

## `setTrailers(Trailers $trailers): void`

Assigns the [`Trailers`](trailers.md) object to be used in the response. Trailers are sent once the entire response body has been set to the client.

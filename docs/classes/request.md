---
title: Request
permalink: /classes/request
---
# Request

The **`Request`** class represents an HTTP request. It's used in [request handlers](request-handler.md) and [middleware](middleware.md).

* Table of Contents
{:toc}

## Constructor

```php
public function __construct(
    Client $client, 
    string $method, 
    Psr\Http\Message\UriInterface $uri, 
    string[] | string[][] $headers = [], 
    RequestBody | Amp\ByteStream\InputStream | string | null $body = null, 
    string $protocol = "1.1",
    ?Trailers $trailers = null
)
```

### Parameters

|[`Client`](client.md)|`$client`|The client sending the request|
|`string`|`$method`|HTTP request method|
|[`Psr\Http\Message\UriInterface`](https://www.php-fig.org/psr/psr-7/#35-psrhttpmessageuriinterface)|`$uri`|The full URI being requested, including host, port, and protocol|
|`string[]`<br />`string[][]`|`$headers`|An array of strings or an array of string arrays.|
|[`RequestBody`](request-body.md)<br />[`Amp\ByteStream\InputStream`](https://amphp.org/byte-stream/)<br />`string`<br />`null`|`$body`|Request body|
|`string`|`$protocol`|HTTP protocol version|

## `getClient(): Client`

Returns the [`Сlient`](client.md) sending the request

## `getMethod(): string`

Returns the HTTP method used to make this request, e.g. `"GET"`.

## `setMethod(string $method): void`

Sets the request HTTP method.

## `getUri(): Psr\Http\Message\UriInterface`

Returns the request [`URI`](https://www.php-fig.org/psr/psr-7/#35-psrhttpmessageuriinterface).

## `setUri(Psr\Http\Message\UriInterface $uri): void`

Sets a new [`URI`](https://www.php-fig.org/psr/psr-7/#35-psrhttpmessageuriinterface) for the request.

## `getProtocolVersion(): string`

Returns the HTTP protocol version as a string (e.g. "1.0", "1.1", "2.0").

## `setProtocolVersion(string $protocol)`

Sets a new protocol version number for the request.

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

## `getBody(): RequestBody`

Returns the request body. The [`RequestBody`](request-body.md) allows streamed and buffered access to an [`InputStream`](https://amphp.org/byte-stream/).

## `setBody(RequestBody | InputStream | string | null $stringOrStream)`

Sets the stream for the message body

{:.note}
> Using a string will automatically set the `Content-Length` header to the length of the given string.
> Setting an [`InputStream`](https://amphp.org/byte-stream/#inputstream) will remove the `Content-Length` header.
> If you know the exact content length of your stream, you can add a `content-length` header _after_ calling `setBody()`.

If `$stringOrStream` value is not valid, [`\TypeError`](http://php.net/manual/en/class.typeerror.php) is thrown. 

## `getCookies(): RequestCookie[]`

Returns all [cookies](https://amphp.org/http/cookies) in associative map

## `getCookie(string): ?RequestCookie`

Gets a [cookie](https://amphp.org/http/cookies) value by name or `null`.

## `setCookie(RequestCookie $cookie): void`

Adds a [`Cookie`](https://amphp.org/http/cookies) to the request.

If `$cookie` value is not valid, [`\Error`](http://php.net/manual/en/class.error.php) is thrown.

## `removeCookie(string $name): void`

Removes a cookie from the request.

## `hasAttribute(string $name): bool`

Check whether an attribute with the given name exists in the request's mutable local storage.

## `getAttribute(string $name): mixed`

Retrieve a variable from the request's mutable local storage. 

{:.note}
> Name of the attribute should be namespaced with a vendor and package namespace, like classes.

## `setAttribute(string $name, mixed $value): void`

Assign a variable to the request's mutable local storage. 

{:.note}
> Name of the attribute should be namespaced with a vendor and package namespace, like classes.

## `getTrailers(): Trailers`

Allows access to the [`Trailers`](trailers.md) of a request.

## `setTrailers(Trailers $trailers): void`

Assigns the [`Trailers`](trailers.md) object to be used in the request.

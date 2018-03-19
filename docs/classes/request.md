---
title: Request
permalink: /classes/request
---
# Request

The **`Request`** class represents an HTTP request. It's used in [request handlers](request-handler.md) and [`middleware`](middleware.md).

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
    string $protocol = "1.1"
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

## `setMethod(string $method)`

Sets the request HTTP method.

## `getUri(): Psr\Http\Message\UriInterface`

Returns the request [`URI`](https://www.php-fig.org/psr/psr-7/#35-psrhttpmessageuriinterface).

## `setUri(Psr\Http\Message\UriInterface $uri)`

Sets a new [`URI`](https://www.php-fig.org/psr/psr-7/#35-psrhttpmessageuriinterface) for the request.

## `getProtocolVersion(): string`

Returns the HTTP protocol version as a string (e.g. "1.0", "1.1", "2.0").

## `setProtocolVersion(string $protocol)`

Sets a new protocol version number for the request.

## `setHeader(string $name, string | string[] $value)`

Sets the named header to the given value.

If header `$name` is not valid or `$value` is invalid, [`\Error`](http://php.net/manual/en/class.error.php) is thrown. 

{:.note}
> To verify header `$name` and `$value` [`Message::setHeader`](message.md#setheaderstring-name-string--string-value) is used 

## `addHeader(string $name, string | string[] $value)`

Adds the value to the named header, or creates the header with the given value if it did not exist.

If header `$name` is not valid or `$value` is invalid, [`\Error`](http://php.net/manual/en/class.error.php) is thrown. 

{:.note}
> To verify header `$name` and `$value` [`Message::addHeader`](message.md#addheaderstring-name-string--string-value) is used

## `removeHeader(string $name)`

Removes the given header if it exists.

## `getBody(): RequestBody`

Returns the request body. The [`RequestBody`](request-body.md) allows streamed and buffered access to an [`InputStream`](https://amphp.org/byte-stream/).

## `setBody(RequestBody | InputStream | string | null $stringOrStream)`

Sets the stream for the message body

{:.note}
> Using a string will automatically set the `Content-Length` header to the length of the given string. Using an [`InputStream`](https://amphp.org/byte-stream/) or [`Body`](request-body.md) instance will remove the `Content-Length` header.

If `$stringOrStream` value is not valid, [`\TypeError`](http://php.net/manual/en/class.typeerror.php) is thrown. 

## `getCookies(): RequestCookie[]`

Returns all [cookies](https://amphp.org/http/cookies) in associative map

## `getCookie(string): RequestCookie | null`

Gets a [cookie](https://amphp.org/http/cookies) value by name or `null`.

## `setCookie(RequestCookie $cookie)`

Adds a [`Cookie`](https://amphp.org/http/cookies) to the request.

If `$cookie` value is not valid, [`\Error`](http://php.net/manual/en/class.error.php) is thrown.

## `removeCookie(string $name)`

Removes a cookie from the request.

## `getAttribute(string $name): mixed`

Retrieve a variable from the request's mutable local storage. 

{:.note}
> Name of the attribute should be namespaced with a vendor and package namespace, like classes.

## `setAttribute(string $name, mixed $value)`

Assign a variable to the request's mutable local storage. 

{:.note}
> Name of the attribute should be namespaced with a vendor and package namespace, like classes.

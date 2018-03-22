---
title: Trailers
permalink: /classes/trailers
---
The **`Trailers`** class allows access to the trailers of an HTTP request.

## Constructor

```php
public function __construct(
    array $headers
)
```

### Parameters

|`array`|`$headers`|Trailer headers.|

## `getHeaders(): array`

Returns the headers as a string-indexed array of arrays of strings or an empty array if no headers have been set.

## `hasHeader(string $name): bool`

Checks if given header exists.

## `getHeaderArray(string $name): array`

Returns the array of values for the given header or an empty array if the header does not exist.

## `getHeader(string $name): ?string`

Returns the value of the given header.
If multiple headers are present for the named header, only the first header value will be returned.
Use `getHeaderArray()` to return an array of all values for the particular header.
Returns `null` if the header does not exist.

## `setHeaders(array $headers)`

Sets the headers from the given array.

## `setHeader(string $name, string | string[] $value)`

Sets the header to the given value(s).
All previous header lines with the given name will be replaced.

## `addHeader(string $name, string | string[] $value)`

Adds an additional header line with the given name.

## `removeHeader(string $name)`

Removes the given header if it exists.
If multiple header lines with the same name exist, all of them are removed.

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

## `setHeader(string $name, string | string[] $value)`

Sets the header to the given value(s).
All previous header lines with the given name will be replaced.

## `addHeader(string $name, string | string[] $value)`

Adds an additional header line with the given name.

## `removeHeader(string $name)`

Removes the given header if it exists.
If multiple header lines with the same name exist, all of them are removed.

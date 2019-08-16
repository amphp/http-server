---
title: Trailers
permalink: /classes/trailers
---
The **`Trailers`** class allows access to the trailers of an HTTP request.

## Constructor

```php
public function __construct(
    Promise $headers,
    array $fields = []
)
```

### Parameters

|`Promise<string[]|string[][]>`|`$headers`|Promise for trailer header values.|
|`string[]`|`$fields`|Trailer header field names.|

## `getFields(): string[]`

Returns the declared trailer fields to be expected in the trailers. If given, the field names in the trailers must match exactly.

## `awaitMessage(): Promise<Message>`

Returns a promise that is resolved with an instance of `Amp\Http\Message` when the trailers are received.
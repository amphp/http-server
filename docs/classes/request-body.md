---
title: RequestBody
permalink: /classes/request-body
---
**`RequestBody`** extends [`Payload`](https://amphp.org/byte-stream/payload) and allows streamed and buffered access to an [`InputStream`](https://amphp.org/byte-stream/#inputstream). 
Additionally, it allows increasing the body size limit dynamically and allows access to the request trailers.

## Constructor

```php
public function __construct(
    InputStream $stream,
    ?callable $upgradeSize = null
)
```

### Parameters

|[`InputStream`](https://amphp.org/byte-stream/#inputstream)|`$stream`|Request payload.|
|`callable`<br>`null`|`$upgradeSize`|Callback used to increase the maximum size of the body.|
|`Promise`<br>`null`|`$trailers`|Promise for trailing headers.|

## `increaseSizeLimit(int $limit): void`

Increases the size limit dynamically if an `$upgradeSize` callback is present.
Otherwise this is a no-op.

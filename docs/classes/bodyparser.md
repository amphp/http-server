---
title: BodyParser
permalink: /classes/bodyparser
---
The `BodyParser` is the `Promise` to the [`ParsedBody`](parsedbody.md).

You typically get a `BodyParser` instance by calling the `parseBody(Request, int $size = 0)` function.

## `Promise::onResolve(callable(ClientException|null, string))`

This class implements `Promise`, resolving to an instance of [`ParsedBody`](parsedbody.md) or failing with `ClientException` if parsing fails. Yield this instance in a coroutine to buffer and then parse the entire body. Yield this object in a coroutine to buffer and parse the entire request body at once.

## `fetch(): Promise`

The returned Promise is resolved with a field name as soon as it starts being processed or `null` if no more fields remain.

## `stream(string $name): FieldBody`

Returns the **next** `FieldBody` for a given `$name` and sets the size limit for that field to `$size` bytes.

Note the emphasis on _next_, it thus is possible to fetch multiple equally named fields by calling `stream()` repeatedly.

## Example

```php
// $request being an instance of Request
// Note this is 2 MB *TOTAL*, for all the file fields.
$body = Aerys\parseBody($request, 2 << 20 /* 2 MB */);
$field = $body->stream("file");
while (null !== $data = yield $field->read()) {
    $metadata = yield $field->getMetadata();
    if (!isset($metadata["filename"])) {
        $res->setStatus(Amp\Http\Status::BAD_REQUEST);
        return;
    }
    // This obviously is only fine when this is an admin panel and user can be trusted
    // else further validation is required!
    $handle = Amp\File\open("files/".$metadata["filename"], "w+");
    do {
        $handle->write($data);
    } while (null !== ($data = yield $field->read()));
    $field = $body->stream("file");
}
```
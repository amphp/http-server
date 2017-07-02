---
title: BodyParser
permalink: /classes/bodyparser
---
The `BodyParser` is the `Promise` to the [`ParsedBody`](parsedbody.html).

You typically get a `BodyParser` instance by calling the `parseBody(Request, int $size = 0)` function.

## `Promise::onResolve(callable(ClientException|null, string))`

If an instance of this class is yielded or `onResolve()` is used, it will either throw or pass a `ClientException` as first parameter, or return an instance of [`ParsedBody`](parsedbody.html) or pass it as second parameter, when all data has been fetched.

## `Amp\ByteStream\InputStream::read(): Promise`

The returned Promise is resolved with a field name as soon as it starts being processed.

## `stream(string $name, int $size = 0): FieldBody`

Returns the **next** `FieldBody` for a given `$name` and sets the size limit for that field to `$size` bytes.

Note the emphasis on _next_, it thus is possible to fetch multiple equally named fields by calling `stream()` repeatedly.

If `$size` <= 0, the last specified size is used, if none present, it's counting toward total size; if `$size` > 0, the current field has a size limit of `$size`.

## Example

```php
# $req being an instance of Request
$body = Aerys\parseBody($req);
# Note this is 2 MB *TOTAL*, for all the file fields.
$field = $body->stream("file", 2 << 20 /* 2 MB */);
while (null !== $data = yield $field->read()) {
    $metadata = yield $field->getMetadata();
    if (!isset($metadata["filename"])) {
        $res->setStatus(HTTP_STATUS["BAD_REQUEST"]);
        return;
    }
    // This obviously is only fine when this is an admin panel and user can be trusted
    // else further validation is required!
    $handle = Amp\file\open("files/".$metadata["filename"], "w+");
    do {
        $handle->write($data);
    } while (null !== ($data = yield $field->read()));
    $field = $body->stream("file");
}
```
---
title: FieldBody
permalink: /classes/fieldbody
---

`FieldBody` extends [`Amp\ByteStream\Message`](//amphp.org/byte-stream/message) is a class of which instances are returned by the [`BodyParser`](bodyparser.md).

It provides one function in addition to the inherited methods from [`Amp\ByteStream\Message`](//amphp.org/byte-stream/message).

## `getMetadata(): Promise<array<"filename" => string, "mime" => string>>`

Returns a `Promise` to the metadata array, as defined by [`ParsedBody`](parsedbody.md).
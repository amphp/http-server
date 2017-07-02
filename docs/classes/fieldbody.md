---
title: FieldBody in Aerys
title_menu: FieldBody
layout: docs
---

`FieldBody` extends [`Amp\ByteStream\Message`](../../byte-stream/message) [@TODO bogus link, Amp\ByteStream\Message docs missing] is a class of which instances are returned by the [`BodyParser`](bodyparser.html).

It provides one function in addition to the inherited methods from [`Amp\ByteStream\Message`](../../byte-stream/message) [@TODO bogus link, Amp\ByteStream\Message docs missing].

## `getMetadata(): Promise<array<"filename" => string, "mime" => string>>`

Returns a `Promise` to the metadata array, as defined by [`ParsedBody`](parsedbody.html).
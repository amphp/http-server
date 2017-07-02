---
title: FieldBody in Aerys
title_menu: FieldBody
layout: docs
---

`FieldBody` extends [`Body`](body-message.html) is a class of which instances are returned by the [`BodyParser`](bodyparser.html).

It provides (in addition to the inherited methods from [`Body`](body-message.html)) two functions.

## `defined(): Promise<bool>`

Returns a boolean whether the field the `FieldBody` instance has been requested for, had been passed at all by the client.

This function is basically equivalent to a call to [`valid()`](body-message.html) before any [`consume()`](body-message.html) call.

## `getMetadata(): Promise<array<"filename" => string, "mime" => string>>`

Returns a `Promise` to the metadata array, as defined by [`ParsedBody`](parsedbody.html).
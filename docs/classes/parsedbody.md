---
title: ParsedBody
permalink: /classes/parsedbody
---

* Table of Contents
{:toc}

A `ParsedBody` instance is in general returned by a `Promise` returned by [`BodyParser`](bodyparser.md).

## `get(string $name): string|null`

Fetch a string parameter (or null if it doesn't exist).

## `getArray(string $name): array`

Fetch an array parameter (may return an empty array).

## `getMetadata(string $name): array<"filename" => string, "mime" => string>|null`

Contains an `array("filename" => $name, "mime" => $mimetype)`.

Elements may be missing, but in case a filename is provided, mime is always set.

## `getMetadataArray(string $name): array<array<"filename" => string, "mime" => string>>`

Similar to `getMetadata()`, but fetches it as an array, with indices equivalent to the data returned by `getArray($name)`.

## `getNames(): array`

Returns the names of the passed fields.

## `getAll(): array<"fields" => array<array<string>>, "metadata" => array<array<array<"filename" => string, "mime" => string>>>>`

Returns two associative fields and metadata arrays (like for extended abstractions or debug).
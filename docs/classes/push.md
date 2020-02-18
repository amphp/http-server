---
title: Push
permalink: /classes/push
---

* Table of Contents
{:toc}

The **`Push`** class represents a pushed resource in a [`Response`](response.md). `Push` objects are not created by the user, rather resources should be pushed to the client using the `Response::push()` method. An array of `Push` objects is returned by the `Response::getPushes()` method.

## `getHeaders(): string[][]`

Returns the headers as a string-indexed array of arrays of strings or an empty array if no headers have been set.

## `hasHeader(string $name): bool`

Checks if given header exists.

## `getHeaderArray(string $name): string[]`

Returns the array of values for the given header or an empty array if the header does not exist.

## `getHeader(string $name): ?string`

Returns the value of the given header.
If multiple headers are present for the named header, only the first header value will be returned.
Use `getHeaderArray()` to return an array of all values for the particular header.
Returns `null` if the header does not exist.

## `getUri(): Psr\Http\Message\UriInterface`

Returns the pushed resource's [`URI`](https://www.php-fig.org/psr/psr-7/#35-psrhttpmessageuriinterface).

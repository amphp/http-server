---
title: Body or Websocket\Message in Aerys
title_menu: Body / Websocket\Message
layout: docs
---

* Table of Contents
{:toc}

The only appearance of the `Websocket\Message` is in [`Websocket::onOpen`](websocket.html#onOpen) and of `Body` is in [`Response::getBody`](response.html#getBody) and [`InternalRequest->body`](middleware.html#internalrequest-body).

It is the `Promise` to the message string, but also implementing `PromiseStream` (important for larger bodies).

## `Promise::when(callable(ClientException|null, string))`

If an instance of this class is yielded or `when()` is used, it will either throw or pass a `ClientException` as first parameter, or return a string or pass it as second parameter, which contains the whole data, when all data has been fetched.

## `Promise::watch(callable(string))`

Partial string updates are passed to the passed callable. You probably don't want this method, but use `consume()` and `valid()`, as with `watch()` the string parts are still buffered.

## `PromiseStream::valid(): Promise<bool>`

This method returns a Promise which always resolves to true when a new update appears, or to false when no further updates will come.

## `PromiseStream::consume(): string`

Gets the next part of the message. Only call this method when the `Promise` returned by `valid()` resolved to false.

## Example

```php
$string = yield $message; // gets the data string all at once
```

```php
while (yield $message->valid()) {
	$incrementalProcessor->feed($message->consume());
}
```

> Warning: The former example is fine in case you have low limits (like maximum two megabytes of data) to prevent people from easily DoS'ing you by just sending much, much and even more data.

In case you need to get much data in, you should use the `PromiseStream` (`valid()`/`consume()`) API in order to incrementally process data without buffering it.

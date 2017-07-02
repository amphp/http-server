---
title: Client in Aerys
title_menu: Client
layout: docs
---

* Table of Contents
{:toc}

This is a value class exposing the whole data of the clients request via public properties. It is only accessible via [`InternalRequest`](internalrequest.html) as well as [`HttpDriver`](httpdriver.html).

Values marked with a <sup>†</sup> **_must_** not be altered in order to not bring the server down.

## `$id`<sup>†</sup>

An unique client id (unique as long as the client object is alive).

## `$socket`<sup>†</sup>

The client socket resource.

## `$clientAddr`<sup>†</sup>

The IP address of the client.

## `$clientPort`<sup>†</sup>

The port of the client.

## `$serverAddr`<sup>†</sup>

The IP address this server was accessed at.

## `$serverPort`<sup>†</sup>

The port the client connected to.

## `$isEncrypted`<sup>†</sup>

Whether the stream is encrypted

## `$cryptoInfo`<sup>†</sup>

Only relevant if `$isEncrypted == true`.

Is equivalent to the `stream_get_meta_data($socket)["crypto"]` array.

## `$requestParser`

Is a Generator returned by [`HttpDriver::parser()`](httpdriver.html).

## `$readWatcher`<sup>†</sup>

The read watcher identifier returned by `Amp\onReadable` for the `$socket`. May be disabled or enabled to stop or resume reading from it, especially in [`HttpDriver`](httpdriver.html).

## `$writeWatcher`<sup>†</sup>

The write watcher identifier returned by `Amp\onWritable` for the `$socket`.

## `$writeBuffer`

The data pending to be written to the `$socket`. The Server will remove data from this buffer as soon as they're written.

## `$bufferSize`

Size of the internal buffers, supposed to be compared against `$options->softStreamCap`. It is decreased by the Server upon each successful `fwrite()` by the amount of written bytes.

## `$bufferPromisor`

Eventually containing a `Promisor` when `$bufferSize` is exceeding `$options->softStreamCap`.

## `$onWriteDrain`

A callable for when the `$writeBuffer` will be empty again. [The `Server` may overwrite it.]

## `$shouldClose`

Boolean whether the next request will terminate the connection.

## `$isDead`<sup>†</sup>

One of `0`, `Client::CLOSED_RD`, `Client::CLOSED_WR` or `Client::CLOSED_RDWR`, where `Client::CLOSED_RDWR === Client::CLOSED_RD | Client::CLOSED_WR` indicating whether read or write streams are closed (or both).

## `$isExported`<sup>†</sup>

Boolean whether the `$export` callable has been called.

## `$remainingKeepAlives`

Number of remaining keep-alives.

## `$pendingResponses`

The number of responses not yet completely replied to.

## `$options`

The [`Options`](options.html) instance.

## `$httpDriver`

The [`HttpDriver`](httpdriver.html) instance used by the client.

## `$exporter`

A callable requiring the `Client` object as first argument. It unregisters the client from the [`Server`](server.html) and returns a callable, which, when called, decrements counters related to rate-limiting.

(Unstable, may be altered in near future)

## `$bodyPromisors`

An array of `Deferred`s whose `Promise`s have been passed to [`InternalRequest->body`](internalrequest.html). You may `fail()` **and then** `unset()` them.

If the `$client->bodyPromisors[$internalRequest->streamId]` entry exists, this means the body is still being processed.

## `$parserEmitLock`

A boolean available for use by a [`HttpDriver`](httpdriver.html) instance (to regulate parser halts and avoid resuming already active Generators).

## `$allowsPush`

Boolean whether the client allows push promises (HTTP/2 only).
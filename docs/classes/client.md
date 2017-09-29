---
title: Client
permalink: /classes/client
---
`Aerys\Client` is a value class exposing the whole data of the client's request via public properties. It is only accessible via [`InternalRequest`](internalrequest.md) as well as [`HttpDriver`](httpdriver.md).

Values marked with a <sup>†</sup> **_must_** not be altered in order to not bring the server down.

`$id`<sup>†</sup> | An unique client id (unique as long as the client object is alive).
`$socket`<sup>†</sup> | The client socket resource.
`$clientAddr`<sup>†</sup> | The IP address of the client.
`$clientPort`<sup>†</sup> | The port of the client.
`$serverAddr`<sup>†</sup> | The IP address this server was accessed at.
`$serverPort`<sup>†</sup> | The port the client connected to.
`$isEncrypted`<sup>†</sup> | Whether the stream is encrypted
`$cryptoInfo`<sup>†</sup> | Only relevant if `$isEncrypted == true`. Is equivalent to the `stream_get_meta_data($socket)["crypto"]` array.
`$requestParser` | Is a Generator returned by [`HttpDriver::parser()`](httpdriver.md).
`$readWatcher`<sup>†</sup> | The read watcher identifier returned by `Amp\onReadable` for the `$socket`. May be disabled or enabled to stop or resume reading from it, especially in [`HttpDriver`](httpdriver.md).
`$writeWatcher`<sup>†</sup> | The write watcher identifier returned by `Amp\onWritable` for the `$socket`.
`$writeBuffer` | The data pending to be written to the `$socket`. The Server will remove data from this buffer as soon as they're written.
`$bufferSize` | Size of the internal buffers, supposed to be compared against `$options->softStreamCap`. It is decreased by the Server upon each successful `fwrite()` by the amount of written bytes.
`$bufferDeferred` | Eventually containing a `Deferred` when `$bufferSize` is exceeding `$options->softStreamCap`.
`$onWriteDrain` | A callable for when the `$writeBuffer` will be empty again. [The `Server` may overwrite it.]
`$shouldClose` | Boolean whether the next request will terminate the connection.
`$isDead`<sup>†</sup> | One of `0`, `Client::CLOSED_RD`, `Client::CLOSED_WR` or `Client::CLOSED_RDWR`, where `Client::CLOSED_RDWR === Client::CLOSED_RD | Client::CLOSED_WR` indicating whether read or write streams are closed (or both).
`$isExported`<sup>†</sup> | Boolean whether the `$export` callable has been called.
`$remainingRequests` | Number of remaining requests until the connection will be forcefully killed.
`$pendingResponses` | The number of responses not yet completely replied to.
`$options` | The [`Options`](options.md) instance.
`$httpDriver` | The [`HttpDriver`](httpdriver.md) instance used by the client.
`$exporter` | A callable requiring the `Client` object as first argument. It unregisters the client from the [`Server`](server.md) and returns a callable, which, when called, decrements counters related to rate-limiting. (Unstable, may be altered in near future)
`$bodyEmitters` | An array of `Emitter`s whose `Iterator`s have been passed to [`InternalRequest->body`](internalrequest.md). You may `fail()` **and then** `unset()` them. If the `$client->bodyPromisors[$internalRequest->streamId]` entry exists, this means the body is still being processed.
`$parserEmitLock` | A boolean available for use by a [`HttpDriver`](httpdriver.md) instance (to regulate parser halts and avoid resuming already active Generators).
`$allowsPush` | Boolean whether the client allows push promises (HTTP/2 only).

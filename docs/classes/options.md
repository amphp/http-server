---
title: Options and the Options class in Aerys
title_menu: Options
layout: docs
---

* Table of Contents
{:toc}

The `Options` class exposes no methods, just properties. The properties may only be set during `Server` startup, after that, they're locked from further writes.

## `$debug`

Indicates whether debug mode is active.

Type: boolean

## `$user`

Only relevant under *nix systems when the server is started as root; it indicates to which user the server will switch to after startup.

Type: string &mdash; Default: current user

## `$maxConnections`

Maximum number of total simultaneous connections the server accepts. If that number is exceeded, new connections are dropped.

Type: integer greater than 0 &mdash; Default: `1000`

## `$connectionsPerIP`

Maximum number of allowed connections from an individual /32 IPv4 or /56 IPv6 range.

Type: integer greater than 0 &mdash; Default: `30`

## `$maxKeepAliveRequests`

Maximum number of keep-alive requests on a single connection.

Set to `PHP_INT_MAX` to effectively disable this limit.

Type: integer greater than 0 &mdash; Default: `1000`

## `$keepAliveTimeout`

Time in seconds after a keep-alive connection is timed out (if no further data comes in). [This also affects HTTP/2]

Type: integer greater than 0 &mdash; Default: `6`

## `$defaultContentType`

Content type of responses, if not otherwise specified by the request handler.

Type: string &mdash; Default: `"text/html"`

## `$defaultTextCharset`

Text charset of text/ content types, if not otherwise specified by the request handler.

Type: string &mdash; Default: `"utf-8"`

## `$sendServerToken`

Whether a `Server` header field should be sent along with the response.

Type: boolean &mdash; Default: `false`

## `$disableKeepAlive`

Whether keep-alive should be disabled for HTTP/1.1 requests.

Type: boolean &mdash; Default: `false

## `$socketBacklogSize`

Size of the backlog, i.e. how many connections may be pending in an unaccepted state.

Type: integer greater than or equal to 16 &mdash; Default: `128

## `$normalizeMethodCase`

Whether method names shall be lowercased.

Type: boolean &mdash; Default: `true`

## `$maxConcurrentStreams`

Maximum number of concurrent HTTP/2 streams per connection.

Type: integer greater than 0 &mdash; Default: `20`

## `$maxFramesPerSecond`

Maximum number of frames a HTTP/2 client is allowed to send per second.

Type: integer &mdash; Default: `60`

## `$allowedMethods`

Array of allowed HTTP methods. [The [`Router`](router.html) class will extend this array with the used methods.]

Type: array&lt;string> &mdash; Default: `["GET", "POST", "PUT", "PATCH", "HEAD", "OPTIONS", "DELETE"]`

## `$deflateEnable`

Whether HTTP body compression should be active.

Type: boolean &mdash; Default: `extension_loaded("zlib")`

## `$deflateContentTypes`

A regular expression to match the content-type against in order to determine whether a request shall be deflated or not.

Type: string &mdash; Default: `"#^(?:text/.*+|[^/]*+/xml|[^+]*\+xml|application/(?:json|(?:x-)?javascript))$#i"`

## `$configPath`

Path to the used configuration file

Type: string

## `$maxFieldLen`

Maximum length of a field name of parsed bodies.

Type: integer greater than 0 &mdash; Default: `16384`

## `$maxInputVars`

Maximum number of input vars (in query string or parsed body).

Type: integer greater or equal to 0 &mdash; Default: `200`

## `$maxBodySize`

Default maximum size of HTTP bodies. [Can be increased by calling [`HttpDriver::upgradeBodySize($ireq)`](HttpDriver.html) or more commonly [`Response::getBody($size)`](response.html).]

Type: integer greater than or equal to 0 &mdash; Default: `131072`

## `$maxHeaderSize`

Maximum header size of a HTTP request.

Type: integer greater than 0 &mdash; Default: `32768`

## `$ioGranularity`

Granularity at which reads from the socket and into the bodies are performed.

Type: integer greater than 0 &mdash; Default: `32768`

## `$softStreamCap`

Limit at which the internal buffers are considered saturated and resolution of `Promise`s returned by `Response::stream()` is delayed until the buffer sizes fall below it.

Type: integer greater than or equal to 0 &mdash; Default: `131072`

## `$deflateMinimumLength`

Minimum length before any compression is applied.

Type: integer greater than or equal to 0 &mdash; Default: `860`

## `$deflateBufferSize`

Buffer size before data is compressed (except it is ended or flushed before).

Type: integer greater than 0 &mdash; Default: `8192`

## `$chunkBufferSize`

Buffer size before data is being chunked (except it is ended or flushed before).

Type: integer greater than 0 &mdash; Default: `8192`

## `$outputBufferSize`

Buffer size before data is written onto the stream (except it is ended or flush before).

Type: integer greater than 0 &mdash; Default: `8192`

## `$shutdownTimeout`

Milliseconds before the Server is forcefully shut down after graceful stopping has been initiated.

Type: integer greater or equal to 0 &mdash; Default: `3000`
---
title: Options
permalink: /classes/options
---

* Table of Contents
{:toc}

The `Options` class represents possible settings for the [HTTP server](server.md). The `Options` is an immutable class, it never modifies itself but returns a new object instead.

## `isInDebugMode(): bool`

Indicates whether the server is in debug mode (`true`) or in production mode (`false`).

{:.note}
> The server mode is set to production by default.

## `withDebugMode(): Options`

Sets the server mode to debug.

## `withoutDebugMode(): Options`

Sets the server mode to production.

## `getConnectionLimit(): int`

Returns the maximum number of connections that can be handled by the server at a single time. If that number is exceeded, new connections are dropped.

{:.note}
> Default connection limit is `10000`.

## `withConnectionLimit(int $limit): Options`

Sets the maximum number of connections the server should accept at one time. If that number is exceeded, new connections are dropped.

{:.warning}
> Connection limit must be greater than or equal to one.

{:.note}
> Default connection limit is `10000`.

## `getConnectionsPerIpLimit(): int`

Returns the maximum number of connections allowed from an individual /32 IPv4 or /56 IPv6 range.

{:.note}
> Default connections per IP limit is `30`.

## `withConnectionsPerIpLimit(int $count): Options`

Sets the maximum number of connections to allow from a single IP address.

{:.warning}
> Connections per IP limit must be greater than or equal to one.

{:.note}
> Default connections per IP limit is `30`.

## `getConnectionTimeout(): int`

Returns amount of time in seconds a connection may be idle before it is automatically closed.

{:.note}
> Default connection timeout is `15` seconds.

## `withConnectionTimeout(int $seconds): Options`

Sets the number of seconds a connection may be idle before it is automatically closed.

{:.warning}
> Connection timeout must be greater than or equal to one second.

{:.note}
> Default connection timeout is `15` seconds.

## `getBodySizeLimit(): int`

Returns maximum HTTP request body size in bytes.

{:.note}
> Default body size limit is `131072` bytes (128k).

## `withBodySizeLimit(int $bytes): Options`

Sets maximum request body size in bytes. Individual request body may be increased by calling [`RequestBody::increaseSizeLimit`](request-body.md).

{:.warning}
> Body size limit must be greater than or equal to zero.

{:.note}
> Default body size limit is `131072` bytes (128k).

## `getHeaderSizeLimit(): int`

Returns maximum size of the request header section in bytes.

{:.note}
> Default header size limit is `32768` bytes (32k).

## `withHeaderSizeLimit(int $bytes): Options`

Sets maximum size of the request header section in bytes.

{:.warning}
> Header size limit must be greater than zero.

{:.note}
> Default header size limit is `32768` bytes (32k).

## `getConcurrentStreamLimit(): int`

Returns the maximum number of concurrent HTTP/2 streams per connection.

{:.note}
> Default concurrent streams limit is `20`.

## `withConcurrentStreamLimit(int $streams): Options`

Sets the maximum number of concurrent HTTP/2 streams per connection.

{:.warning}
> Concurrent streams limit must be greater than zero.

{:.note}
> Default concurrent streams limit is `20`.

## `getMinimumAverageFrameSize(): int`

Returns minimum average frame size required if more than the maximum number of frames per second are received on an HTTP/2 connection.

{:.note}
> Default minimum average frame size is `1024` bytes (1k).

## `withMinimumAverageFrameSize(int $size): Options`

Sets minimum average frame size required if more than the maximum number of frames per second are received on an HTTP/2 connection.

{:.warning}
> Minimum average frame size must be greater than zero.

{:.note}
> Default minimum average frame size is `1024` bytes (1k).

## `getFramesPerSecondLimit(): int`

Returns the maximum number of HTTP/2 frames per second before the average length minimum is enforced.

{:.note}
> Default frames per second limit is `60`.

## `withFramesPerSecondLimit(int $frames): Options`

Sets the maximum number of HTTP/2 frames per second before the average length minimum is enforced.

{:.warning}
> Maximum number of HTTP/2 frames per second setting must be greater than zero.

{:.note}
> Default frames per second limit is `60`.

## `getChunkSize(): int`

Returns the maximum number of bytes to read from a client per read.

{:.note}
> Default frames per second limit is `8192` (8k).

## `withChunkSize(int $bytes): Options`

Sets the maximum number of bytes to read from a client per read.

{:.warning}
> Maximum number of HTTP/2 frames per second setting must be greater than zero.

{:.note}
> Default frames per second limit is `8192` (8k).

## `getAllowedMethods(): array`

Returns an array of allowed request methods.

{:.note}
> Default allowed methods are:
> `["GET", "POST", "PUT", "PATCH", "HEAD", "OPTIONS", "DELETE"]`

## `withAllowedMethods(array $allowedMethods): Options`

Sets an array of allowed request methods.

{:.warning}
> Allowed methods must be an array of non-empty strings and contain GET and HEAD methods:
> `["GET", "HEAD"]`

{:.note}
> Default allowed methods are:
> `["GET", "POST", "PUT", "PATCH", "HEAD", "OPTIONS", "DELETE"]`

## `isHttp2UpgradeAllowed(): bool`

Returns `true` if HTTP/2 requests may be established through upgrade requests or prior knowledge.

{:.note}
> HTTP/2 requests through upgrade requests are disabled by default.

## `withHttp2Upgrade(): Options `

Enables unencrypted upgrade or prior knowledge requests to HTTP/2.

## `withoutHttp2Upgrade(): Options`

Disables unencrypted upgrade or prior knowledge requests to HTTP/2.

## `isCompressionEnabled(): bool`

Returns `true` if compression is enabled.

{:.note}
> Compression is enabled by default.

## `withCompression(): Options`

Enables compression-by-default.

## `withoutCompression(): Options`

Disables compression-by-default.

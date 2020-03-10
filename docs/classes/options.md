---
title: Options
permalink: /classes/options
---

* Table of Contents
{:toc}

The `Options` class represents possible settings for the [HTTP server](http-server.md). The `Options` is an immutable class, it never modifies itself but returns a new object instead.

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

## `getHttp1Timeout(): int`

Returns amount of time in seconds an HTTP/1.x connection may be idle before it is automatically closed.

{:.note}
> Default HTTP/1.x connection timeout is `15` seconds.

## `withHttp1Timeout(int $seconds): Options`

Sets the number of seconds an HTTP/1.x connection may be idle before it is automatically closed.

{:.warning}
> HTTP/1.x connection timeout must be greater than or equal to one second.

{:.note}
> Default HTTP/1.x connection timeout is `15` seconds.

## `getHttp2Timeout(): int`

Returns amount of time in seconds an HTTP/2` connection may be idle before it is automatically closed.

{:.note}
> Default HTTP/2 connection timeout is `60` seconds.

## `withHttp2Timeout(int $seconds): Options`

Sets the number of seconds an HTTP/2 connection may be idle before it is automatically closed.

{:.warning}
> HTTP/2 connection timeout must be greater than or equal to one second.

{:.note}
> Default HTTP/2 connection timeout is `60` seconds.

## `getTlsSetupTimeout(): int`

Returns amount of time in seconds that can elapse during TLS setup before a connection is automatically closed.

{:.note}
> Default TLS setup timeout is `5` seconds.

## `withTlsSetupTimeout(int $seconds): Options`

Sets the number of seconds that may elapse during TLS setup before a connection is automatically closed.

{:.warning}
> TLS setup timeout must be greater than or equal to one second.

{:.note}
> Default TLS setup timeout is `5` seconds.

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

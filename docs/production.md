---
title: Running on Production
permalink: /production
---

## General

- Set your `ulimit -n` (maximum open file descriptors) high enough to manage all your connections. Recommended is at least `$workers * (Options->getConnectionLimit() + 100)`. [100 is an arbitrary number usually big enough for all the persisting file descriptors. If not enough, add more.]
- In case you are using a properly configured load-balancer in front of Amp's HTTP servers, you should set the number of connections near to the maximum the host system can handle.
- Amp's HTTP server has a [static content server](https://github.com/amphp/http-server-static-content), which isn't too bad (use libuv if you use it!), but for heavy loads, a CDN is recommended.
- Avoid a low [`memory_limit`](http://php.net/manual/en/ini.core.php#ini.memory-limit) setting, it is one of the few things able to kill the server ungracefully. If you have a memory leak, fix it, instead of relying on the master process to restart it.

## Options

Defaults are chosen in a moderate way between security and performance on a typical machine.

- In _debug mode_ HTTP server sends any debugging-related information and to the logger. Debug mode is disabled by default. To enable it, make sure you run HTTP server with debug mode option and set log level to `DEBUG`.
- The _connection limit_ is important to prevent the server from going out of memory in combination with body and header size limit and (for HTTP/2) _concurrent stream limit_ option.
- The _connections per IP limit_ ratelimits the number of connections from a single IP, to avoid too many connections being dropped off. Be aware that websocket and HTTP/2 connections are persistent. It's recommended to carefully balance the maximum connections per IP and the _connection limit_ option. It just is a simple layer of security against trivial DoS attacks, but won't help against DDoS, which will be able to just hold all the connections open.
- The _connection timeout_ is a keepalive timeout. Conventional wisdom says to limit it to a short timeout.
- The _body size limit_ is recommended to be set to the lowest necessary for your application. If it is too high, people may fill your memory with useless data. (It is always possible to increase it at runtime, see [RequestBody::increaseSizeLimit](classes/request-body.md)).
- The _header size limit_ should never need to be touched except if you have 50 KB of cookies.
- The _chunk size_ is the maximum size of chunks into which the response body is sliced. A too low value results in higher overhead. A too high value impairs prioritization due to [head-of-line blocking](https://en.wikipedia.org/wiki/Head-of-line_blocking).
- The _concurrent stream limit_ is the maximum of concurrent HTTP/2 streams on a single connection. Do not set it too high (but neither too low to not limit concurrency) to avoid trivial attacks.
- The _frames per second limit_ is the maximum number of frames a HTTP/2 client may send per second before being throttled. Do not set it too high (but neither too low to not limit concurrency) to avoid attacks consisting of many tiny frames.
- The _minimum average frame size_ is the size of the largest frame payload that the sender is willing to receive. The value advertised by an endpoint MUST be between the default value (16KB) and the maximum allowed frame size (16MB), inclusive. Smaller frame size enables efficient multiplexing and minimizes [head-of-line blocking](https://en.wikipedia.org/wiki/Head-of-line_blocking).
- The _compression_ is recommended to be enabled. Compressing responses sent to clients can greatly reduce their size, so they use less network bandwidth.
- The _allow HTTP/2 upgrade_ is disabled by default because you can only upgrade to an insecure (cleartext) HTTP/2 connection.

---
title: Running on Production
permalink: /production
---
## General

- Set your `ulimit -n` (maximum open file descriptors) high enough to manage all your connections. Recommended is at least `$workers * (Options->maxConnections + 100)`. [100 is an arbitrary number usually big enough for all the persisting file descriptors. If not enough, add more.]
- Ratelimit the number of connections from a single IP (at least if you have no clever load-balancer) via for example iptables, to avoid too many connections being dropped off. Be aware that websocket and HTTP/2 connections are persistent. It's recommended to carefully balance the maximum connections per IP (proxys!) and the maxConnections option. It just is a simple layer of security against trivial DoS attacks, but won't help against DDoS, which will be able to just hold all the connections open.
- In case you are using a properly configured load-balancer in front of Aerys servers, you should set the number of connections near to the maximum the host system can handle.
- Aerys has a file server, which isn't too bad (use libuv if you use it!), but for heavy loads, a CDN is recommended.
- Avoid a low `memory_limit` setting, it is one of the few things able to kill the server ungracefully. If you have a memory leak, fix it, instead of relying on the master process to restart it.

## Options

Defaults are chosen in a moderate way between security and performance on a typical machine.

- `maxConnections` is important to prevent the server from going out of memory in combination with maximum body and header size and (for HTTP/2) `maxConcurrentStreams` option.
- `maxBodySize` is recommended to be set to the lowest necessary for your application. If it is too high, people may fill your memory with useless data. (It is always possible to increase it at runtime, see [usage of Response::getBody($size)](body.html).)
- `maxHeaderSize` should never need to be touched except if you have 50 KB of cookies ...
- `softStreamCap` is a limit where `Response::write()` returns an unresolved Promise until buffer is empty enough again. If you do not have much memory, consider lowering it, if you have enough, possibly set it a bit higher. It is not recommended to have it higher than available memory divided by `maxConnections` and `maxStreams` and 2 (example: for 8 GB memory, 256 KB buffer is fine). [Should be a multiple of `outputBufferSize`.]
- `maxConcurrentStreams` is the maximum of concurrent HTTP/2 streams on a single connection. Do not set it too high (but neither too low to not limit concurrency) to avoid trivial attacks.
- `maxFramesPerSecond` is the maximum number of frames a HTTP/2 client may send per second before being throttled. Do not set it too high (but neither too low to not limit concurrency) to avoid attacks consisting of many tiny frames.
- `maxInputVars` limits the number of input vars processed by `Response` and `BodyParser`. This is especially important to be small enough in order to prevent HashDos attacks and overly much processing.
- `maxFieldLen` limits field name lengths in order to avoid excessive buffering (which would defeat any possibilities of incremental parsing).
- `ioGranularity` is buffering input and output - data will be usually sent after the defined amount of buffered data and fed into the `Body` after receiving at least that amount of data.
- `disableKeepAlive` is possible, but not recommended to be ever deactivated (it will automatically avoid keep-alive with HTTP/1.0).
- `socketBacklogSize` is the queue size of sockets pending acceptance (i.e. being in queue for an `accept()` call by the `Server`).
- `deflateContentTypes` is a regular expression containing the content-types of responses to be deflated. If you use a bit more exotic content-types for deflatable content not starting with `text/` or ending with `/xml` or `+xml` or equal to `application/(json|(x-)?javascript)`, you should extend the regex appropriately.
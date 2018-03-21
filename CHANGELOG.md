### 0.7.4

 - Fixed an issue where the timing of WebSocket writes could cause out of order messages.

### 0.7.3

 - Allow `amphp/file ^0.3`
 - Fixed sending pending responses when shutting down the server (#211)
 - More portable CPU count detection (#207)
 - WebSocket updates:
    - Reject websocket frames if RSV is not equal to `0` (will update as extensions are supported).
    - Accept zero-length frames starting a message.
    - Reject continuations if there's no started message.
    - Disable streaming for frames. A single frame is now always buffered. A message can still be streamed via multiple frames.

### 0.7.2

 - Fixed reading request port with HTTP/2

### 0.7.1

 - Fixed connection hangs if the process is forked while serving a request. See #182 and #192.

### 0.7.0

 - Fixed incorrect log level warning.
 - Fixed issue with referenced IPC socket blocking server shutdown.
 - Added support for unix sockets.
 - Added support for wildcard server names such as `localhost:*`, `*:80` and `*:*`.
 - Fixed buggy HTTP/1 pipelining.
 - Handle promises returned from generators in the config file correctly.
 - Added `-u` / `--user` command line option.
 - Correctly decode URL parameters with `urldecode()` instead of `rawurldecode()`.
 - Fixed freeze of websocket reading with very low `maxFramesPerSecond` and `maxBytesPerMinute`.
 - Removed `Router::__call()` magic, use `Router::route()` instead.

### 0.6.2

Retag of `v0.6.0`, as `v0.6.1` has been tagged wrongly, which should have been `v0.7.0`.

### 0.6.1

**Borked release.** Should have been `v0.7.0` and has been tagged as `v0.7.0` now.

### 0.6.0

Initial release based on Amp v2.

- Config files must return an instance of `Host` or an array of `Host` instances.
- `Aerys\Response::stream()` renamed to `write()` so that `Aerys\Response` may implement `Amp\ByteStream\OutputStream`.
- `Aerys\WebSocket\Endpoint::send()` split into three methods: `send()`, `broadcast()`, and `multicast()`.
- `Aerys\Body` removed. `Request::getBody()` returns an instance of `Amp\ByteStream\Message`.

### 0.5.0

_No information available._

### 0.4.7

_No information available._

### 0.4.6

_No information available._

### 0.4.5

_No information available._

### 0.4.4

_No information available._

### 0.4.3

 - Implemented monitoring system.
 - Always properly close HTTP/1.0 connections.
 - Fixed wildcard address matching.

### 0.4.2

_No information available._

### 0.4.1

 - Fixed caching issues in `Router` if multiple methods exist for specific URIs.
 
### 0.4.0

_No information available._

### 0.3.0

_No information available._

### 0.2.0

_No information available._

### 0.1.0

_No information available._

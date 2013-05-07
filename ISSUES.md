# Known Issues

These are things I'm fully aware of and plan to fix but haven't gotten around to yet ...

#### Message Parsing

- Chunked entity body parsing is slow and inefficient -- it needs improvement
- Message parsing *will* bork and throw a `ParseException` if deprecated chunked-encoding extensions
are specified as part of a chunk delimiter. This **MUST** be fixed prior to releasing for
public consumption.
- Trailer headers following a chunked body are currently ignored and will cause a `ParseException`
if present in the entity body. This too is a **MUST** fix.

#### ReverseProxy

- Though connections to backend servers are non-blocking once established, the actual connection
attempt blocks. Ideally these connections need to be performed without blocking.
- An exponential backoff algorithm should be used when reconnecting to dead backend sockets instead
of the current paradigm which upon the first reconnection failure disregards the backend socket
entirely. If no other backends are specified in the proxy configuration this will lead to all
requests being answered with a `503 Service Unavailable` response.
- TLS currently cannot be used between the front-facing server and backend sockets. At this time
this functionality is extremely low on the priority list.

#### Mods

- The logging mod (`Aerys\Mods\ModLog`) currently performs non-blocking writes, but this needs to be
ported to use asynchronous calls and allow batching instead of triggering a write on each request.

#### Websockets

- The websocket functionality works well by all accounts, but is has yet to undergo *rigorous* testing.
Both its public API and under-the-hood functionality remain subject to change.

#### Testing

- Needs moar test coverage.

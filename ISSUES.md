# Known Issues

These are things I'm fully aware of and plan to fix but haven't gotten around to yet ...

#### Message Parsing

- Chunked entity body parsing is slow and inefficient -- it needs improvement
- Message parsing *will* bork and throw a `ParseException` if deprecated chunked-encoding extensions
are specified as part of a chunk delimiter. This **MUST** be fixed prior to releasing for
public consumption.
- Trailer headers following a chunked body are currently ignored and will cause a `ParseException`
if present in the entity body. This is a **MUST** fix.

#### ReverseProxy

- TLS currently cannot be used between the front-facing proxy server and backend sockets. This is fairly
low on the priority list and may not even be necessary.

#### Mods

- The logging mod (`Aerys\Mods\ModLog`) currently performs non-blocking writes on each log event.This
should be modified to allow write batching and flushing at periodic intervals instead of incurring
the write cost on each request.

#### Websockets

- The websocket functionality works well by all accounts, but it has yet to undergo *rigorous* testing.
Both its public API and under-the-hood functionality remain subject to change.

#### Testing

- Needs moar test coverage.

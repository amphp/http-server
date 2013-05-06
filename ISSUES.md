# Known Issues

These are things I'm aware of and plan to fix but haven't gotten around to yet.

#### Message Parsing

- Chunked entity body parsing is slow and inefficient -- needs work
- Message parsing will bork if deprecated chunked-encoding extensions are specified
- Trailer headers following a chunked body are currently ignored and will cause a ParseException

#### ReverseProxy

- Though non-blocking once established, backend sockets are currently connected in a blocking manner.
Connections should ideally be made in a non-blocking manner.
- TLS currently cannot be used between the front-facing server and backend sockets. I may not ever
implement this. I'm not totally convinced this feature is even necessary.
- An exponential backoff algorithm should be used for backend socket reconnection attempts instead of what
happens now: a single reconnection attempt which, if it fails, means the backend will not be reconnected.

#### Mods

- Logging mod currently performs non-blocking writes, but should be ported to use asynchronous
calls and batching instead of writing immediately for every request.

#### Websockets

- Works well by all accounts, but hasn't yet been *rigorously* tested. Both the public API and
under-the-hood functionality remain subject to change. There are also a few @TODOs hanging out
in the implementation code for extreme edge cases.

#### Testing

- Needs moar coverage

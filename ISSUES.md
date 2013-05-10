# Known Issues

These are things I'm fully aware of and plan to fix but haven't gotten around to yet ...

#### Message Parsing

- Chunked entity body parsing is slow and inefficient -- it needs improvement
- Message parsing *will* bork and throw a `ParseException` if deprecated chunked-encoding extensions
are specified as part of a chunk delimiter. This **MUST** be fixed prior to releasing for
public consumption.
- Trailer headers following a chunked body are currently ignored and will cause a `ParseException`
if present in the entity body. This is a **MUST** fix.

#### Testing

- Needs moar test coverage.

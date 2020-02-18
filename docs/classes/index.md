---
title: Classes
permalink: /classes/
---
The `http-server` package provides a set of classes and interfaces:

- [`Client`](client.md) &mdash; Client connection related information
- [`HttpDriver`](http-driver.md) &mdash; Driver for interaction with the raw socket
- [`HttpServer`](http-server.md) &mdash; The server, tying everything together
- [`Options`](options.md) &mdash; Accessor of options
- [`Push`](push.md) &mdash; Represents resourced pushed to the client through responses
- [`Request`](request.md) &mdash; Request class representing client requests
- [`RequestBody`](request-body.md) &mdash; Request body
- [`RequestHandler`](request-handler.md) &mdash; Request handler interface
- [`Response`](response.md) &mdash; Response class for responding to client requests in request handlers
- [`ServerObserver`](server-observer.md) &mdash; Registers method to be notified upon Server state changes
- [`Trailers`](trailers.md) &mdash; Access request trailers

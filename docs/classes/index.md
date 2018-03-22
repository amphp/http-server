---
title: Classes
permalink: /classes/
---
The `http-server` package provides a set of classes and interfaces:

- [`Client`](client.md) &mdash; Client connection related information
- [`HttpDriver`](http-driver.md) &mdash; Driver for interaction with the raw socket
- [`Options`](options.md) &mdash; Accessor of options
- [`Request`](request.md) &mdash; Request class for request handler callables
- [`RequestBody`](request-body.md) &mdash; Request body
- [`RequestHandler`](request-handler.md) &mdash; Request handler interface 
- [`Response`](response.md) &mdash; General response interface for responder callables
- [`Server`](server.md) &mdash; The server, tying everything together
- [`ServerObserver`](server-observer.md) &mdash; Registers method to be notified upon Server state changes
- [`Trailers`](trailers.md) &mdash; Access request trailers

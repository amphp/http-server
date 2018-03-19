---
title: Classes
permalink: /classes/
---
The `http-server` package provides a set of classes and interfaces, as well as [functions](functions.md):

- [`Client`](client.md) &mdash; Client connection related information
- [`HttpDriver`](httpdriver.md) &mdash; Driver for interaction with the raw socket
- [`Logger`](logger.md) &mdash; PSR-3 compatible logger
- [`Message`](message.md) &mdash; An abstract class represents HTTP message
- [`Options`](options.md) &mdash; Accessor of options
- [`Request`](request.md) &mdash; Request class for request handler callables
- [`RequestBody`](request-body.md) &mdash; Request body
- [`RequestHandler`](request-handler.md) &mdash; Request handler interface 
- [`Response`](response.md) &mdash; General response interface for responder callables
- [`Server`](server.md) &mdash; The server, tying everything together
- [`ServerObserver`](serverobserver.md) &mdash; Registers method to be notified upon Server state changes

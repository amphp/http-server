---
title: Classes
permalink: /classes/
---
Aerys provides a set of classes and interfaces, as well as [functions](functions.md):

- [`BodyParser`](bodyparser.md) &mdash; Parser for bodies
- [`Bootable`](bootable.md) &mdash; Registers entry point for [`Server`](server.md) and [`Logger`](logger.md)
- [`Client`](client.md) &mdash; Client connection related information
- [`CommandClient`](commandclient.md) &mdash; Controls the server master process
- [`FieldBody`](fieldbody.md) &mdash; Field body message container (via [`BodyParser`](bodyparser.md))
- [`Filter`](filter.md) &mdash; Defines a filter callable in `do()` method
- [`Host`](host.md) &mdash; Registers a virtual host
- [`HttpDriver`](httpdriver.md) &mdash; Driver for interaction with the raw socket
- [`InternalRequest`](internalrequest.md) &mdash; Request related information
- [`Logger`](logger.md) &mdash; PSR-3 compatible logger
- [`Options`](options.md) &mdash; Accessor of options
- [`ParsedBody`](parsedbody.md) &mdash; Holds request body data in parsed form
- [`Request`](request.md) &mdash; General request interface for responder callables
- [`Response`](response.md) &mdash; General response interface for responder callables
- [`Router`](router.md) &mdash; Manages and accumulates routes
- [`Server`](server.md) &mdash; The server, tying everything together
- [`ServerObserver`](serverobserver.md) &mdash; Registers method to be notified upon Server state changes
- [`Websocket`](websocket.md) &mdash; General websocket connection manager
- [`Websocket\Endpoint`](websocket-endpoint.md) &mdash; Provides API to communicate with a websocket client

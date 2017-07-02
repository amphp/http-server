---
title: Using InternalRequest in Aerys Middlewares
title_menu: Overview
layout: docs
---

Aerys provides a set of classes and interfaces, as well as [functions](functions.html):

- [`Body`](body-message.html) &mdash; Request body message container
- [`BodyParser`](bodyparser.html) &mdash; Parser for bodies
- [`Bootable`](bootable.html) &mdash; Registers entry point for [`Server`](server.html) and [`Logger`](logger.html)
- [`Bootstrapper`](bootstrapper.html) &mdash; Initializes [`Server`](server.html) (for custom process management)
- [`Client`](client.html) &mdash; Client connection related information
- [`CommandClient`](commandclient.html) &mdash; Controls the server master process
- [`FieldBody`](fieldbody.html) &mdash; Field body message container (via [`BodyParser`](bodyparser.html))
- [`Host`](host.html) &mdash; Registers a virtual host
- [`HttpDriver`](httpdriver.html) &mdash; Driver for interaction with the raw socket
- [`InternalRequest`](internalrequest.html) &mdash; Request related information
- [`Logger`](logger.html) &mdash; PSR-3 compatible logger
- [`Middleware`](middleware.html) &mdash; Defines a middleware callable in `do()` method
- [`Options`](options.html) &mdash; Accessor of options
- [`ParsedBody`](parsedbody.html) &mdash; Holds request body data in parsed form
- [`Request`](request.html) &mdash; General request interface for responder callables
- [`Response`](response.html) &mdash; General response interface for responder callables
- [`Router`](router.html) &mdash; Manages and accumulates routes
- [`Server`](server.html) &mdash; The server, tying everything together
- [`ServerObserver`](serverobserver.html) &mdash; Registers method to be notified upon Server state changes
- [`Websocket`](websocket.html) &mdash; General websocket connection manager
- [`Websocket\Endpoint`](websocket-endpoint.html) &mdash; Provides API to communicate with a websocket client
- [`Websocket\Message`](body-message.html) &mdash; Websocket message container
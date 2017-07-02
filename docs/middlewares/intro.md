---
title: Middlewares in Aerys
title_menu: Introduction
layout: tutorial
---

Middlewares can be `use()`'d via a `Host` instance or the `Router`.

They are able to manipulate responses as well as the request data before the application callable can read from them.

Even internal state of the connection can be altered by them. They have powers to break in deeply into the internals of the server.

> **Warning**: Middlewares are technically able to directly break some assumptions of state by the server by altering certain values. Keep middlewares footprint as small as possible and only change what really is needed!

For example websockets are using a middleware to export the socket from within the server accessible via `InternalRequest->client->socket`.

Most middlewares though will only need to manipulate direct request input (headers) and operate on raw response output.
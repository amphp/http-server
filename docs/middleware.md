---
title: Middleware
permalink: /middleware
---

A `Middleware` allows to pre-process requests and post-process responses.
Use cases include a caching or authentication layer, but can also be as simple as adding a few headers to a response.

The `Middleware` interface has a single `process()` method that receives a `Request` and `RequestHandler` and must return a `Promise` that resolves to a `Response`.
If the middleware decides to handle the request itself, it can directly return a response without delegating to the received `RequestHandler`, otherwise the `RequestHandler` is responsible for creating a response.

{:.image-80}
![Middleware interaction](./latex/middleware.png)

{% include undocumented.md %}

---
title: Middleware
permalink: /middleware
---

The `Middleware` interface allows to pre-process requests and post-process responses.
It's `process()` method receives a `Request` and `Responder` and must return a `Promise` that resolves to a `Response`.
If the middleware decides to handle the request itself, it can directly return a response without delegating to the received `Responder`, otherwise the `Responder` is responsible for creating a response.

{% include undocumented.md %}

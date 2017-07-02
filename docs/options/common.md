---
title: Setting Aerys options
title_menu: Common options
layout: tutorial
---

This describes the common options, not affecting performance; to fine-tune your servers limits, look at the [production specific options](../performance/production.html).

- `defaultContentType` The default content type of responses (Default: `"text/html"`)
- `defaultTextCharset` The default charset of `text/` content types (Default: `"utf-8"`)
- `sendServerToken` Whether to send a `Server` header with each request (Default: `false`)
- `normalizeMethodCase` Whether method names should be always automatically uppercased (Default: `true`)
- `allowedMethods` An array of allowed methods - if the method is not in the list, the request is terminated with a 405 Method not allowed (Default: `["GET", "POST", "PUT", "PATCH", "HEAD", "OPTIONS", "DELETE"]`)
- `shutdownTimeout` A timeout in milliseconds the server is given to gracefully shutdown (Default: `3000`)
- `user` The user (only relevant for Unix) under which the server runs. [This is important from a security perspective in order to limit damage if there's ever a breach in your application!] (No default - set it yourself!)
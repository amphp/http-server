---
title: What's left of possibilites in Aerys
title_menu: Everything else
layout: tutorial
---

That's it - nearly.

Aerys has a powerful responder callable mechanism, coupled to middlewares with routing based upon promises and non-blocking I/O. Beyond that ...

It has HTTP/1 and HTTP/2 drivers. It provides a possibility to define a custom driver, see the [`HttpDriver` class docs](../classes/httpdriver.html).

Furthermore, it is possible to control (in non-debug mode) the Server (externally or within Aerys) via the [`CommandClient` class](../classes/commandclient.html).

When the server is started up or shut down, it is possible to be notified of these events via the [`ServerObserver` class](../classes/serverobserver.html).

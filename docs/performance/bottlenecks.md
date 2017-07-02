---
title: ! 'Performance Introduction: Aerys is not a bottleneck'
title_menu: Introduction
layout: tutorial
---

Aerys in general is not a bottleneck. Misconfiguration, use of blocking I/O or inefficient applications are.

Aerys is well-optimized and can handle tens of thousands of requests per second on typical hardware while maintaining a high level of concurrency of thousands of clients.

But that performance will decrease drastically with inefficient applications. Aerys has the nice advantage of classes and handlers being always loaded, so no time lost with compilation and initialization.

A common trap is to begin operating on big data with simple string operations, requiring many inefficient big copies, which is why it is strongly recommended to use [incremental body parsing](body.html) when processing larger incoming data, instead of processing the data all at once.

The problem really is CPU cost. Inefficient I/O management (as long as it is non-blocking!) is just delaying individual requests [it is recommended to dispatch simultaneously and eventually bundle multiple independent I/O requests via Amp combinators], but a slow handler will slow down every other request too: While one handler is computing, all the other handlers (Generators) can't continue. Thus it is imperative to reduce computation times of the handlers to a minimum.
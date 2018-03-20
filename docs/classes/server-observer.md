---
title: ServerObserver
permalink: /classes/server-observer
---

The `ServerObserver` interface is necessary to be able to watch for state changes of the [`Server`](server.md).
Classes implementing it can also access the default server logger and error handler on startup.

Classes aggregating request handlers SHOULD check if they implement `ServerObserver` and delegate these events.

* Table of Contents
{:toc}

## `onStart(Server): Promise`

Invoked when the server is starting.
Server sockets have been opened, but are not yet accepting client connections.
This method should be used to set up any necessary state for responding to requests, including starting loop watchers such as timers.
Accepting connections is deferred until the returned promise resolves.

## `onStop(Server): Promise`

Invoked when the server has initiated stopping.
No further requests are accepted and any connected clients should be closed gracefully and any loop watchers cancelled.

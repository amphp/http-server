---
title: Introduction
permalink: /
---
Amp's HTTP server is a non-blocking HTTP/1.1 and HTTP/2 application server written in PHP.
This means that there's no Apache or Nginx required to serve PHP applications with it.
Multiple requests can be served concurrently and the application bootstrapping only needs to happen once, not once for every request.

## Installation

The server can be installed as a [Composer](https://getcomposer.org/) dependency.

```bash
composer require amphp/http-server
```

Several advanced components are available in separate packages, such as a [routing](https://github.com/amphp/http-server-router), [static content](https://github.com/amphp/http-server-static-content) and [WebSocket](https://github.com/amphp/websocket-server) component.

## Examples

Several examples can be found in the [`./examples`](https://github.com/amphp/http-server/tree/master/examples) directory of the [repository](https://github.com/amphp/http-server).
These can be executed as normal PHP scripts on the command line.

```bash
php examples/hello-world.php
```

You can then access the example server at [`http://localhost:1337/`](http://localhost:1337/) in your browser.

## Logging

The `Server` uses a `NullLogger` by default.
If you pass a `Psr\Log\LoggerInterface` instance to its constructor, you'll get helpful log messages.

{:.note}
> Internally generated log messages of the `DEBUG` level are only generated if `zend.assertions` is set to `1`.
> If it isn't set to `1` in your config, load the server with `php -d zend.assertions=1 examples/hello-world.php`.

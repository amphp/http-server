# http-server

[![Build Status](https://travis-ci.org/amphp/http-server.svg?branch=master)](https://travis-ci.org/amphp/http-server)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/amphp/http-server/blob/master/LICENSE)

This package provides a non-blocking HTTP/1.1 and HTTP/2 application server written in PHP based on [Amp](https://github.com/amphp/amp).
Several features are provided in separate packages, such as the [WebSocket component](https://github.com/amphp/websocket-server).

## Features

- [Static file serving](https://github.com/amphp/http-server-static-content)
- [WebSockets](https://github.com/amphp/websocket-server)
- [Dynamic app endpoint routing](https://github.com/amphp/http-server-router)
- Full TLS support
- Customizable GZIP compression
- HTTP/2.0 support
- Middleware hooks

## Requirements

- PHP 7

## Installation

```bash
composer require amphp/http-server
```

## Documentation

- [Official Documentation](http://amphp.org/http-server/)

## Example

```php
<?php

Amp\Loop::run(function () {
    $server = new Amp\Http\Server\Server(new CallableResponder(function (Request $request) {
        return new Amp\Http\Server\Response("Hello, World!");
    }));
    
    yield $server->start();
    
    Amp\Loop::onSignal(SIGINT, function () use ($server) {
        yield $server->stop();
    });
});
```

```bash
php example.php
```

## Security

If you discover any security related issues, please email bobwei9@hotmail.com or me@kelunik.com instead of using the issue tracker.

## License

The MIT License (MIT). Please see [LICENSE](./LICENSE) for more information.

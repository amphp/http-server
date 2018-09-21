<h1 align="center"><img src="https://raw.githubusercontent.com/amphp/logo/master/repos/http-server.png?v=21-09-2018" alt="HTTP Server" width="350"></h1>

[![Build Status](https://travis-ci.org/amphp/http-server.svg?branch=master)](https://travis-ci.org/amphp/http-server)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/amphp/http-server/blob/master/LICENSE)

This package provides a non-blocking HTTP/1.1 and HTTP/2 application server written in PHP based on [Amp](https://github.com/amphp/amp).
Several features are provided in separate packages, such as the [WebSocket component](https://github.com/amphp/websocket-server).

The packages was previously named [`amphp/aerys`](https://github.com/amphp/aerys), but has been renamed to be easier to remember, as many people were having issues with the old name.

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

use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Server;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Socket;
use Psr\Log\NullLogger;

// Run this script, then visit http://localhost:1337/ in your browser.

Amp\Loop::run(function () {
    $sockets = [
        Socket\listen("0.0.0.0:1337"),
        Socket\listen("[::]:1337"),
    ];
    
    $server = new Server($sockets, new CallableRequestHandler(function (Request $request) {
        return new Response(Status::OK, [
            "content-type" => "text/plain; charset=utf-8"
        ], "Hello, World!");
    }), new NullLogger);

    yield $server->start();

    // Stop the server gracefully when SIGINT is received.
    // This is technically optional, but it is best to call Server::stop().
    Amp\Loop::onSignal(SIGINT, function (string $watcherId) use ($server) {
        Amp\Loop::cancel($watcherId);
        yield $server->stop();
    });
});
```

```bash
php example.php
```

## Contributing

Please read [`CONTRIBUTING.md`](https://github.com/amphp/amp/blob/master/CONTRIBUTING.md) for details on our code of conduct, and the process for submitting pull requests to us.

## Security

If you discover any security related issues, please email [`contact@amphp.org`](mailto:contact@amphp.org) instead of using the issue tracker.

## License

The MIT License (MIT). Please see [LICENSE](./LICENSE) for more information.

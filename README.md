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
- [Request body parser](https://github.com/amphp/http-server-form-parser)
- [Sessions](https://github.com/amphp/http-server-session)
- Full TLS support
- Customizable GZIP compression
- HTTP/2.0 support
- Middleware hooks
- [CORS](https://github.com/labrador-kennel/http-cors) (3rd party)

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

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use function Amp\Http\Server\handleFunc;
use function Amp\Http\Server\html;
use function Amp\Http\Server\listenAndServe;

// Run this script, then visit http://localhost:8000/ in your browser.

function helloWorld(Request $request): Response
{
    return html('Hello World!');
}

Amp\Loop::run(static function () {
    yield listenAndServe('0.0.0.0:8000', handleFunc('helloWorld'));
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

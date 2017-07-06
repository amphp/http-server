# aerys

[![Build Status](https://travis-ci.org/amphp/aerys.svg?branch=master)](https://travis-ci.org/amphp/aerys)
[![Dependency Status](https://www.versioneye.com/user/projects/56cc2d7918b2710403dfee93/badge.svg)](https://www.versioneye.com/user/projects/56cc2d7918b2710403dfee93)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/amphp/aerys/blob/master/LICENSE)
[![Average time to resolve an issue](http://isitmaintained.com/badge/resolution/amphp/aerys.svg)](http://isitmaintained.com/project/amphp/aerys "Average time to resolve an issue")

Aerys is a non-blocking HTTP/1.1 and HTTP/2 application, WebSocket and static file server written in PHP based on the [`amp`](https://github.com/amphp/amp) concurrency framework.

Aerys has currently a few 0.x tags. APIs are still subject to very small changes and you may run into rogue ~~bugs~~ features. We love PRs, though :-)

## Selected Built-in Features ...

- Static file serving
- WebSockets
- Dynamic app endpoint routing
- Name-based virtual hosting
- Full TLS support
- Customizable GZIP output compression
- HTTP/2.0 support
- Middleware hooks

## Requirements

- PHP 7

## Installation

```bash
composer require amphp/aerys
```

## Documentation

- [Official Documentation](http://amphp.org/aerys/)
- [Getting Started with Aerys](http://blog.kelunik.com/2015/10/21/getting-started-with-aerys.html)
- [Getting Started with Aerys WebSockets](http://blog.kelunik.com/2015/10/20/getting-started-with-aerys-websockets.html)

## Running a Server

```bash
php bin/aerys -c demo.php
```

Simply execute the `aerys` binary (with PHP 7) to start a server listening on `http://localhost/` using
the default configuration file (packaged with the repository).

Add a `-d` switch to see some debug output like the routes called etc.:

```bash
php bin/aerys -d -c demo.php
```

## Config File

Use the `-c, --config` switches to define the config file:

```bash
php bin/aerys -c /path/to/my/config.php
```

Use the `-h, --help` switches for more instructions.

## Static File Serving

To start a static file server simply pass a root handler as part of your config file.

```php
(new Aerys\Host)
    ->expose("*", 1337)
    ->use(Aerys\root(__DIR__ . "/public"));
```

## Security

If you discover any security related issues, please email bobwei9@hotmail.com or me@kelunik.com instead of using the issue tracker.

## License

The MIT License (MIT). Please see [LICENSE](./LICENSE) for more information.

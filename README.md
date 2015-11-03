# Aerys [![Build Status](https://travis-ci.org/amphp/aerys.svg?branch=master)](https://travis-ci.org/amphp/aerys)

A non-blocking HTTP/1.1 application, websocket and static file server written in PHP.

Though Aerys has been iterated on for quite a while there no official tagged releases (yet).
APIs are still subject to change and you may run into rogue ~~bugs~~ features. We love PRs, though :)

## Selected Built-in Features ...

- Static file serving
- Websockets
- Dynamic app endpoint routing
- Name-based virtual hosting
- Full TLS support
- Customizable GZIP output compression
- HTTP/2 (in-progress)
- Middleware hooks

## Requirements

- PHP 7

## Installation

```bash
$ composer require amphp/aerys
```

## Documentation

* [Official Documentation](http://amphp.org/docs/aerys/)
* [Getting Started with Aerys](http://blog.kelunik.com/2015/10/21/getting-started-with-aerys.html)
* [Getting Started with Aerys WebSockets](http://blog.kelunik.com/2015/10/20/getting-started-with-aerys-websockets.html)

## Running a Server

```bash
$ php bin/aerys
```

Simply execute the aerys binary (with php7) to start a server listening on `http://localhost/` using
the default configuration file (packaged with the repo).

## Config File

Aerys looks for its config file in the following locations (relative to the repo root):

 - ./config.php
 - ./etc/config.php
 - ./bin/config.php

If none of the relative locations holds a config.php the server checks the following absolute path:

 - /etc/aerys/config.php

The first discovered config.php is used. Alternatively, the `-c, --config` switches define a custom
config file:

```bash
$ php bin/aerys -c /path/to/my/config.php
```

Use the `-h, --help` switches for more instructions.

## Static File Serving

To start a static file server simply pass a root handler as part of your config file.

```php
(new Aerys\Host)
    ->expose("*", 1337)
    ->use(Aerys\root(__DIR__ . "/public"));
```

## Example Host Configurations

@TODO

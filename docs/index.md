---
title: Aerys
description: Aerys is a non-blocking HTTP/1.1 and HTTP/2 application / websocket / static file server.
title_menu: Introduction
layout: docs
---

`amphp/aerys` is a non-blocking HTTP/1.1 and HTTP/2 application, websocket and static file server written in PHP.

## Required PHP Version

- PHP 7

## Optional Extension Backends

- [ev](https://pecl.php.net/package/ev)
- [libevent](https://pecl.php.net/package/libevent)
- [php-uv](https://github.com/bwoebi/php-uv)

## Current Stable Version

Aerys has currently a few 0.x tags. APIs are still subject to very small changes and you may run into rogue <s>bugs</s> features. We love PRs, though :-)

## Installation

```bash
composer require amphp/aerys
```

## First Run

```bash
vendor/bin/aerys -d -c demo.php
```

> **Note:** In production you'll want to drop the `-d` (debug mode) flag. For development it is pretty helpful though. `-c demo.php` tells the program where to find the config file.

## Blog Posts

 - [Getting Started with Aerys](http://blog.kelunik.com/2015/10/21/getting-started-with-aerys.html)
 - [Getting Started with Aerys WebSockets](http://blog.kelunik.com/2015/10/20/getting-started-with-aerys-websockets.html)

<div class="tutorial-next">Start with the <a href="setup/start.html">Tutorial</a> or check the <a href="classes">Classes docs</a> out</div>

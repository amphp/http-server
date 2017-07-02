---
title: Logging in Aerys
title_menu: Logger
layout: docs
---

* Table of Contents
{:toc}

Aerys includes a logger that can be used to `STDOUT`. While being in production mode Aerys uses multiple workers, so all log data is sent to the master process and logged to `STDOUT` there.

The only way to receive the `Logger` instance is to implement [`Bootable`](bootable.html), it will be passed as second argument to its `boot()` method.

By default only messages of severity `warning` or higher will be shown. In debug mode (`-d` / `--debug` flag) the default is lowered to be `debug`. You can adjust the log level using the `-l / --log` option.

The `Logger` class implements the [PSR-3 `Psr\Log\LoggerInterface`](http://www.php-fig.org/psr/psr-3/#3-psr-log-loggerinterface) and thus exposes the same methods: `emergency()`, `alert()`, `critical()`, `error()`, `warning()`, `notice()`, `info()`, `debug()` and the universal `log()`.

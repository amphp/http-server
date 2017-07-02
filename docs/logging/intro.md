---
title: Logging in Aerys
title_menu: Introduction
layout: tutorial
---

Aerys follows [the PSR-3 standard for logging](http://www.php-fig.org/psr/psr-3/).

Aerys uses the warning level by default as minimum - in debug mode it uses the debug level. It is possible to specify any default minimum log level via the `--log` command line option. E.g. `--log info`, which will log everything, except debug level logs.

All logging output is sent to the STDOUT of the master process; thus, to log to a file, all needed is piping the output of the master process to the file.

Additionally, use of ANSI colors (for nicer displaying in terminal) can be turned on or off via `--color on` respectively `--color off`.
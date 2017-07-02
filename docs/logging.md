---
title: Logging
permalink: /logging
---
Aerys follows [the PSR-3 standard for logging](http://www.php-fig.org/psr/psr-3/).

Aerys uses the warning level by default as minimum - in debug mode it uses the debug level. It is possible to specify any default minimum log level via the `--log` command line option. E.g. `--log info`, which will log everything, except debug level logs.

All logging output is sent to the STDOUT of the master process; thus, to log to a file, all needed is piping the output of the master process to the file.

Additionally, use of ANSI colors (for nicer displaying in terminal) can be turned on or off via `--color on` respectively `--color off`.

## Usage

```php
(new Aerys\Host)->use(new class implements Bootable {
    private $logger;

    function boot(Aerys\Server $server, Psr\Log\LoggerInterface $logger) {
        $this->logger = $logger;
    }

    function __invoke(Aerys\Request $req, Aerys\Response $res) {
        $this->logger->debug("Request received!");
    }
});
```

The `Aerys\Bootable` interface provides a `boot(Aerys\Server $server, Psr\Log\LoggerInterface $logger)` function, which is called with the `Aerys\Server` and especially an instance of `Psr\Log\LoggerInterface` before the `Aerys\Server` is actually started. [`STARTING` state]

The passed instance of `Psr\Log\LoggerInterface` can then be stored in e.g. an object property for later use. [As specified by the standard](https://github.com/php-fig/log/blob/master/Psr/Log/LoggerInterface.php), methods from `debug()` to `emergency()` are available.

The signature of these functions is `(string $message, array $context = [])` with `$context` array possibly containing an entry `"time" => $unixTimestamp` [if this one is not present, current time is assumed].
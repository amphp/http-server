# AERYS

HTTP/1.1 webserver written in PHP. Awesomeness ensues. See `./examples` directory for more.

-----------------------------

### REQUIRED DEPENDENCIES

- PHP 5.4+
- [ext/libevent](http://pecl.php.net/package/libevent)
- [Auryn](https://github.com/rdlowrey/Auryn) A dependency injection container used to bootstrap the
HTTP server using a basic configuration array
- [Amp](https://github.com/rdlowrey/Amp) Provides the event reactor and base TCP server classes

Both Auryn and Amp dependencies are linked to the Aerys repository as git submodules. They will be
fetched automatically as long as you pass the `--recursive` options when cloning the repo.

#### OPTIONAL DEPENDENCIES

- [ext/http](http://pecl.php.net/package/pecl_http) Benchmarks demonstrate ~10% speed increase with
the HTTP extension enabled
- [vfsStream](https://github.com/mikey179/vfsStream) Required to execute the full unit test suite 
without skipping some cases.

> **NOTE:** Windows users can find DLLs for both ext/libevent and ext/http at the
> [windows.php.net download index](http://windows.php.net/downloads/pecl/releases/).

### INSTALLATION

```bash
$ git clone --recursive https://github.com/rdlowrey/Aerys.git
```

### HELLO WORLD

```php
require '/hard/path/to/autoload.php'; // <-- change for your local environment

$myApp = function(array $asgiEnv) {
    return [
        $status = 200,
        $reason = 'OK',
        $headers = [],
        $body = '<html><body><h1>Hello, World.</h1></body></html>';
    ];
};

(new Aerys\Config\Configurator)->createServer([[
    'listenOn'      => '*:1337',
    'application'   => $myApp
]])->start();
```

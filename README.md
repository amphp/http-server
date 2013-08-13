# AERYS

HTTP/1.1 webserver written in PHP. Awesomeness ensues. See `./examples` directory for more.

-----------------------------

#### REQUIRED DEPENDENCIES

- PHP 5.4+
- [Auryn](https://github.com/rdlowrey/Auryn) A dependency injection container used to bootstrap the
HTTP server from a basic configuration array
- [Alert](https://github.com/rdlowrey/Alert) Provides the event reactor underlying everything

Both the Auryn and Alert dependencies are linked to the Aerys repository as git submodules. They
will be fetched automatically as long as you pass the `--recursive` options when cloning the repo.

#### OPTIONAL DEPENDENCIES

- [ext/libevent](http://pecl.php.net/package/libevent) Though optional, production servers *SHOULD*
employ the libevent extension -- PHP struggles to handle more 200-300 simultaneously connected clients
without it
- [ext/http](http://pecl.php.net/package/pecl_http) Benchmarks demonstrate ~10% speed increase with
the HTTP extension enabled
- _ext/openssl_ OpenSSL support is needed if you wish to TLS-encrypt socket connections to your
server. This extension is compiled into most PHP distributions by default.
- [vfsStream](https://github.com/mikey179/vfsStream) Required to execute the full unit test suite 
without skipping some cases
- [Artax](https://github.com/rdlowrey/Artax) Required to execute the full integration test suite
without skipping some cases

> **NOTE:** Windows users can find DLLs for both ext/libevent and ext/http at the
> [windows.php.net download index](http://windows.php.net/downloads/pecl/releases/).

#### INSTALLATION & QUICKSTART

```bash
$ git clone --recursive https://github.com/rdlowrey/Aerys.git
$ cd Aerys/bin
$ php aerys.php --config="../examples/hello_world.php"
```

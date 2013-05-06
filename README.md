# AERYS

HTTP/1.1 webserver written in PHP. Awesomeness ensues. See `./examples` directory for more.

### DEPENDENCIES

- PHP 5.4+
- [ext/libevent](http://pecl.php.net/package/libevent)
- [ext/http](http://pecl.php.net/package/pecl_http) (not technically required, but it will speed up
the server by ~10%)
- [Auryn](https://github.com/rdlowrey/Auryn) A dependency injection container used to bootstrap the
HTTP server using a basic configuration array
- [Amp](https://github.com/rdlowrey/Amp) Provides the event reactor and base TCP server classes

Both Auryn and Amp dependencies are linked to the Aerys repository as git submodules. They will be
fetched automatically as long as you pass the `--recursive` options when cloning the repo.

> **NOTE:** Windows users can find DLLs for both ext/libevent and ext/http at the
> [windows.php.net download index](http://windows.php.net/downloads/pecl/releases/).

### INSTALLATION

```bash
$ git clone --recursive https://github.com/rdlowrey/Aerys.git
```

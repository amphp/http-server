---
title: Virtual Hosts
permalink: /hosts
---
Aerys supports virtual hosts by default. Each host needs to be an `Aerys\Host` instance and is automatically registered upon creation of that instance.

```php
return (new Aerys\Host)
    ->expose("127.0.0.1", 8080) // IPv4
    ->expose("::1", 8080) // IPv6
    ->expose("/path/to/unix/domain/socket.sock") // UNIX domain socket
    ->name("localhost") // actually redundant as localhost is the default
    ->use(Aerys\root("/var/www/public_html"));
```

`expose(string $interface, int $port)` binds a host to a specific interface specified by the given IP address (`"*"` binds to _every_ IP interface) and port combination or an UNIX domain socket path. This is where the server will be accessible. The method can be called multiple times to define multiple interfaces to listen on.

`name(string $name)` gives the host a name. The server determines which host is actually accessed (relevant when having multiple hosts on the same interface-port combination), depending on the `Host` header. For the case where the actual port does not match the port specified in the `Host` header, it is possible to append `:port` where `port` is either the port number to match against, or a wildcard `"*"` to allow any port.

The example above will be thus accessible via `http://localhost:8080/` on the loopback interface (i.e. only locally) and via the UNIX domain socket located at `/path/to/unix/domain/socket.sock`.

## Encryption

Aerys supports TLS that can be enabled per host.

```php
return (new Aerys\Host)
    ->expose("*", 443) // bind to everywhere on port 443
    ->encrypt("/path/to/certificate.crt", "/path/to/private.key")
    ->use(Aerys\root("/var/www/public_html"));
```

`encrypt(string $certificate, string|null $key, array $options = [])` enables encryption on a host. It also sets the default port to listen on to `443`.

The `$key` parameter may be set to `null` if the certificate file also contains the private key.

The `$options` array is passed directly as SSL context options and thus equivalent to what is specified by the PHP documentation at [http://php.net/context.ssl](http://php.net/context.ssl). The `$certificate` and `$key` parameters are equivalent to the `local_cert` and `local_pk` options, respectively.

{:.note}
> Due to implementation details, all hosts on a same interface must be either encrypted or not encrypted. Hence it is impossible to e.g. have both http://localhost:8080 and https://localhost:8080 at the same time.

## Adding Handlers

```php
return (new Aerys\Host)
    ->use(Aerys\router()->route('GET', '/', function(Aerys\Request $req, Aerys\Response $res) {
        $res->end("default route");
    }))
    ->use(Aerys\root("/var/www/public_html")) # a file foo.txt exists in that folder
    ->use(function(Aerys\Request $req, Aerys\Response $res) {
        $res->end("My 404!");
    });
```

`Aerys\Host::use()` is the ubiquitous way to install handlers, `Middleware`s, `Bootable`s, and the `HttpDriver`.

Handlers are executed in the order they are passed to `use()`, as long as no previous handler has started the response.

With the concrete example here:

- the path is `/`: the first handler is executed, and, as the route is matched, a response is initiated (`end()` or `write()`), thus subsequent handlers are not executed.
- the path is `/foo.txt`: first handler is executed, but the response is not started (as no route starting a response was matched), then the second, which responds with the contents of the `foo.txt` file.
- the path is `/inexistent`: first and second handlers are executed, but they don't start a response, so the last handler is executed too, returning `My 404!`.

The execution order of `Middleware`s and `Bootable`s solely depends on the order they are passed to `use()` and are always all called. Refer to [the `Middleware`s guide](..md).

A custom `HttpDriver` instance can be only set once per port. It needs to be set on _all_ the host instances bound on a same port. Refer to the [`HttpDriver`](../classes/httpdriver.md).

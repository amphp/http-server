---
title: Host
permalink: /classes/host
---

* Table of Contents
{:toc}

Hosts are the most fundamental entity of configuration; they describe how Aerys can be reached and what it dispatches to. Its functions in general return $this, so one can easily chain calls.

## `use(Filter | Bootable | callable(Request, Response) | Monitor | HttpDriver)`

The way everything is attached to the Host. Currently it accepts `Bootable`s, `Filter`s, callables (the passed argument can also be all three at the same time) and `Monitor`s or a `HttpDriver` instance.

When the server is run `Bootable`s, `Filter`s and callables are called in the order they are passed to `use()`. The `Bootable`s are all called extacly once right before the Server is started. `Filter`s are all invoked each time before the callables are invoked. Then the callables are invoked one after the other *until* the response has been started - the remaining callables are ignored.

{:.note}
> Be careful with `Filter`s, only use them if you really need them. They are expensive as they're called at **each** request. You also can use route-specific `Filter`s to only invoke them when needed.

{:.note}
> There can be only **one** HttpDriver instance per **port** (or UNIX domain socket). That means, if you have multiple `Host` instances listening on the same port, they all need to share the same `HttpDriver` instance!

See also the documentation for [`Filter`s](filter.md) and [`Bootable`s](bootable.md).

## `name(string)`

A name for non-wildcard domains. Like `"www.example.com"`. There only may be one single wildcard Host per interface. All the other `Host`s must have a name specified.

For the case where the actual port does not match the port specified in the `Host` header, it is possible to append `:port` where `port` is either the port number to match against, or a wildcard `"*"` to allow any port.

For example:

- `"*:8080"` to specify a wildcard host, where requests must provide a `Host` header adressing port 8080,
- or `"localhost:*"` specifying a host, which must be addressed on `"localhost"`, but no port matching will happen.

## `expose(string $address, int $port = 0)`

You can specify interfaces the server should listen on with IP and port. By default, if `expose()` is never called, it listens on all IPv4 and IPv6 interfaces on port 80, or 443 if encryption is enabled, basically an implicit `expose("*", $https ? 443 : 80)`. The port number must be between 1 and 65535.

The generic addresses for IPv4 is `"0.0.0.0"`, for IPv6 it is `"::"` and `"*"` for both IPv4 and IPv6.

You also can specify a filesystem path as address. The server will then create an UNIX domain socket at that location. The port number must be 0.

## `encrypt(string $certificatePath, string $keyPath = null, array $additionalSslSettings = [])`

This needs to be set on every `Host` which wants to use https. You may not have both encrypted and unencrypted hosts listening on the same interface and port combination.

The `$keyPath` may be set to `null` if the certificate file also contains the private key.

The `$additionalSslSettings` array is passed directly as SSL context options and thus equivalent to what is specified by the PHP documentation at [http://php.net/context.ssl](http://php.net/context.ssl). The `$certificatePath` and `$keyPath` parameters are equivalent to the `local_cert` and `local_pk` options, respectively.

## Example

```php
return (new Aerys\Host)
    ->expose("127.0.0.1", 80) // Yup, this is the only host here,
    ->name("localhost")       // so expose() and name() aren't necessary
    ->use(function(Aerys\Request $req, Aerys\Response $res) {
        $res->end("<h1>Hello world!</h1>");
    });
```

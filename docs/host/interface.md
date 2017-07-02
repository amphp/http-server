---
title: Exposing Aerys Hosts
title_menu: Exposing
layout: tutorial
---

```php
(new Aerys\Host)
	->expose("127.0.0.1", 8080) // IPv4
	->expose("::1", 8080) // IPv6
	->name("localhost") // actually redundant as localhost is the default
	->use(Aerys\root("/var/www/public_html"));
```

`Aerys\Host::expose(string $interface, int $port)` binds a Host to a specific interface specified by IP address (`"*"` binds to _every_ interface) and port combination. This is from where the server will be accessible. That method can be called multiple times to define multiple interfaces to listen on.

`Aerys\Host::name(string $name)` determines which Host is actually accessed (relevant when having multiple Hosts on the same interface-port combination), depending on the `Host` header.

The example above will be thus accessible via: `http://localhost:8080/` on the loopback interface (i.e. only locally).
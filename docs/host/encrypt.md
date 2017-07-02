---
title: Encrypting Aerys Hosts
title_menu: Encryption
layout: tutorial
---

```php
(new Aerys\Host)
	->expose("*", 443) // bind to everywhere on port 443
	->encrypt("/path/to/certificate.crt", "/path/to/private.key")
	->use(Aerys\root("/var/www/public_html"))
;
```

`Aerys\Host::encrypt(string $certificate, string|null $key, array $options = [])` enables encryption on a Host. It also sets the default port to listen on to `443`.

The `$key` parameter may be set to `null` if the certificate file also contains the key.

The `$options` array is passed directly as SSL context options and thus equivalent to what is specified by the PHP documentation at [http://php.net/context.ssl](http://php.net/context.ssl). The `$certificate` and `$key` parameters are equivalent to the `local_cert` and `local_pk` options, respectively.

> **Note**: Due to implementation details, all hosts on a same interface must be either encrypted or not encrypted. Hence it is impossible to e.g. have both http://localhost:8080 and https://localhost:8080 at the same time.

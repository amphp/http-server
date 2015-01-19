Aerys: TLS Encryption
==================

> **[Table of Contents](#table-of-contents)**

## Crypto Quick Start

Aerys applications configure TLS settings using the `Host::setCrypto()` method shown here:

```php
self Aerys\Host::setCrypto(string $certPath, array $options = []);
```

Because parameter 2 is optional, the only requirement to run a TLS-encrypted domain is a string filesystem path pointing to the server certificate for the host in question.

```php
$myDomain = (new Aerys\Host)
    ->setName('mydomain.com')
    ->setCrypto('/path/to/mydomain.pem')
    ->addResponder(function($request) {
        return '<html>Hello, world.</html>';
    })
;
```

**That's it!**

In the above example we've created an encrypted *https://* server for *mydomain.com* listening on port 443. Our encrypted host supports TLSv1, TLSv1.1 and TLSv1.2 by default (the specific protocols supported may be modified using options as we'll see later).

> **NOTE**
> 
> Unlike unencrypted hosts which default to port 80 if no port is explicitly specified, encrypted hosts default to port 443. For lower port numbers (like 80 or 443) you will need root-level permissions to start the server. Applications not running as root can always use `Host::setPort()` to specify a custom port for testing and development.


### Certificate Considerations

There are a few considerations for the certificate file specified in `Host::setCrypto()`:

- The server certificate must be in .PEM format 
- Your private key should be concatenated along with your public domain certificate in this file
- Any intermediate certificates in the trust chain provided by the certificate authority (CA) which issued your certificate should also be concatenated in this file.
- The root CA cert need not be included; clients already know which roots they trust and as such have no need for your CA's root cert. You can safely omit this in your .pem file and avoid the bandwidth overhead of transferring it for each client connection.

#### Example Server .pem Certificate

> -----BEGIN RSA PRIVATE KEY----- 
... your private key here ...
-----END RSA PRIVATE KEY----- 
-----BEGIN CERTIFICATE----- 
... your primary TLS certificate here ...
-----END CERTIFICATE----- 
-----BEGIN CERTIFICATE----- 
... your intermediate certificate here ...
-----END CERTIFICATE----- 


## Crypto Options

While many applications can get by specifying a certificate and nothing else, some users may wish to customize their encrypted server's behavior. These users can use the optional second parameter, `$options`, when calling `Host::setCrypto()`.

### Crypto Option Reference

Option Key    | Description        | Default
------------- | -------------------|--------
| `"allow_self_signed"` | Allow self-signed client certificates when verifying peer certificates. This option requires `"verify_peer" => true` to have any effect.  | `false` |
| `"auto_redirect"` | If `true` (or truthy), this option will cause the server to redirect all requests for unencrypted resources to the encrypted equivalent. | `false` |
| `"auto_redirect_code"` | Customize the HTTP redirect code used to forward unencrypted requests to the encrypted equivalent.  | `307` |
| `"auto_redirect_port"` | By default the `"auto_redirect"` functionality listens for and redirects unencrypted requests on port 80. Use this key to specify a different port number. | `80` |
| `"cafile"`  | If `"verify_peer"` is true, this directive specifies the trusted CA file to use for peer verification.  | `null` |
| `"capath"`  | If `"verify_peer"` is true, this directive specifies a directory in which trusted CA files may be found for use in peer verification. | `null` |
| `"ciphers"` | The list of allowed ciphers to negotiate during the crypto handshake. Aerys uses PHP's default stream cipher list by default. These ciphers may be viewed by executing this code snippet: `var_dump(OPENSSL_DEFAULT_STREAM_CIPHERS);` | `null` |
| `"crypto_method"` | Bitwise flags specifying the protocols available for negotiation during the crypto handshake. The default protocols are `TLSv1`, `TLSv1.1` and `TLSv1.2`. | [reference](#crypto-method-flag-reference) |
| `"disable_compression"` | Set to `true` to disable TLS protocol compression | `true` |
| `"honor_cipher_order"` | Prefer the ciphers in the order specified by the server during the TLS handshake. | `true` |
| `"passphrase"` | If your private key requires a passphrase you need to specify it here. IMPORTANT: if you store your passphrase here be very careful about who is able to  view your config file. | `null` |
| `"reneg_limit"` | Optionally allow a specified number of TLS protocol renegotiations by the client. Renegotiation is a potential DoS attack vector and most users should simply use the default setting of zero. | `0` |
| `"reneg_limit_callback"` | An optional callback which will be invoked when a client-initiated TLS renegotiation is rate-limited. | `null` |
| `"single_dh_use"` | Always create a new key when using dh parameters with ephemeral key-exchange. This adds security (improved forward secrecy) to the data transfer at the cost of greater processing overhead.  | `false` |
| `"single_ecdh_use"` | Always create a new key when using elliptic curve ciphers for ephemeral key-exchange. This adds security (improved forward secrecy) to the data transfer at the cost of greater processing overhead. | `false` |
|`"ecdh_curve"`| The elliptic curve to use when generating ephemeral keys (to achieve perfect forward secrecy). | `"prime256v1"` |
| `"verify_peer"` | Whether or not clients connecting to the server should have their certificates verified. Do not enable this option unless you know what you're doing. | `false` |


### Crypto Method Flag Reference

By default Aerys will negotiate the best available TLS protocol supported by the connecting client. The encryption protocols a host supports can be customized by specifying bitwise flags consisting of the following constants in the `"crypto_method"` TLS option array.

Method Flag   | Description
------------- | -----------
`STREAM_CRYPTO_METHOD_ANY_SERVER` | Allow any *SSL* or *TLS* protocol (not recommended)
`STREAM_CRYPTO_METHOD_TLS_SERVER` | Allow any *TLS* protocol (default)
`STREAM_CRYPTO_METHOD_TLSv1_0_SERVER` | Allow TLSv1
`STREAM_CRYPTO_METHOD_TLSv1_1_SERVER` | Allow TLSv1.1
`STREAM_CRYPTO_METHOD_TLSv1_2_SERVER` | Allow TLSv1.2
`STREAM_CRYPTO_METHOD_SSLv2_SERVER` | Allow SSLv2 (not recommended)
`STREAM_CRYPTO_METHOD_SSLv3_SERVER` | Allow SSLv3 (not recommended)
`STREAM_CRYPTO_METHOD_SSLv23_SERVER` | Allow SSLv2 or SSLv3 (not recommended)

## Encrypting Multiple Hosts

Aerys utilizes the SNI TLS extension to serve multiple encrypted hosts on the same IP:PORT combination. This obviates the need to procure a separate IP address for each domain certificate your application presents. Simply specify the certificate needed for any encrypted hosts in your application:

```php
<?php

namespace Aerys;

$domain1 = (new Host)
    ->setName('domain1.com')
    ->setCrypto('/path/to/domain1.pem')
    ->addResponder(function($request) {
        return '<html>Hello, world (domain1).</html>';
    })
;

$subdomain1 = (new Host)
    ->setName('subdomain.domain1.com')
    ->setCrypto('/path/to/domain1.pem')
    ->addResponder(function($request) {
        return '<html>Hello, world (subdomain.domain1).</html>';
    })
;

$domain2 = (new Host)
    ->setName('domain2.com')
    ->setCrypto('/path/to/domain2.pem')
    ->addResponder(function($request) {
        return '<html>Hello, world (domain2).</html>';
    })
;
```

> **NOTE**
> 
> Crypto setup is the same regardless of whether or not hosts share an IP address. Aerys automatically employs the SNI TLS extension if necessary.


#### Multi-Host Crypto Limitations

There a couple of limitations to serving encrypted hosts on the same IP:PORT interface:

1. Applications *CANNOT* serve an encrypted host on the same IP:PORT as an unencrypted host. This includes wildcard IP conflicts (e.g. *:1337 and 127.0.0.1:1337). Aerys will fail during the bootstrap phase with an appropriate error message if this scenario arises.
2.  All encrypted hosts on the same IP:PORT must share the same crypto option settings. This is a limitation of the underlying OpenSSL implementation as it relates to server SNI support. All encrypted hosts on the same interface will use the crypto settings defined by the first host declaration on that IP:PORT. The sole difference between the hosts is the actual certificate presented as part of the handshake. If this is problematic for your application you will need to serve the hosts in question from separate IP:PORT interfaces.


## Frequently Asked Questions

### **Q:** How do I redirect all unencrypted requests to the equivalent encrypted resource?

**A:** This is accomplished by setting `"auto_redirect" => true` in your crypto `$options` array as demonstrated here:

```php
$mySite->setCrypto('/path/to/mydomain.pem', $options = [
    "auto_redirect" => true
]);
```

### **Q:** Can I use the same wildcard/SAN certificate for multiple hosts?

**A:** Yes! Simply specify the appropriate cert path for each encrypted host you wish to expose. It's perfectly valid to reuse the same path for multiple hosts.

### **Q:** How do I customize which SSL/TLS protocols my encrypted host supports?

**A:** By default all TLS protocols (1, 1.1, 1.2) are supported. Users wishing to customize the allowed protocols may do so using bitwise crypto method flags as follows:

```php
<?php // Only allow TLSv1.1 and TLSv1.2
$cryptoMethods = STREAM_CRYPTO_METHOD_TLSv1_1_SERVER | STREAM_CRYPTO_METHOD_TLSv1_2_SERVER;

$host = new Aerys\Host;
$host->setCrypto('/path/to/cert.pem', $options = [
    'crypto_method' => $cryptoMethods
]);
```

### **Q:** How do I achieve perfect forward secrecy (PFS) for maximum security in my encrypted host?

The main requirement to achieve "perfect forward secrecy" is ephemeral key exchange. This state protects transfers from being retroactively decrypted in the event that a host's private key is somehow compromised.

There are a couple of requirements to use ephemeral keys. These requirements are slightly different if using DH params instead of ECDH but you don't want to do that:

1. Your private key needs to be an elliptic curve (EC) key. If the private key you used to submit the certificate signing request (CSR) to your CA was not an EC key you will need a new key pair using EC. Your CA will likely charge you for this :)
2. The TLS handshake should prioritize ECDHE ciphers to ensure an appropriate cipher is selected. PHP's default cipher list does this automatically so applications should only consider this if manually specifying their own ciphers in the TLS `$options` array.

For added security at the cost of additional computation time applications may set the `"single_ecdh_use" => true` TLS option to avoid reusing the same ephemeral key for multiple client sessions, though this isn't strictly necessary.

#### Generating an EC Key

For reference, here's an example openssl command to generate an EC key using the prime256v1 curve:

```bash
$ openssl ecparam -name prime256v1 -genkey -noout -out prime256v1-key.pem
```

Users may view a list of curves available on their system with the following command:

```bash
openssl ecparam -list_curves
```


----------------------------------------------

## Table of Contents

[TOC]
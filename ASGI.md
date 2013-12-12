# ASGI

@TODO Intro

## The Application

An ASGI application (the *Application*) is a reference to a PHP callable. This callable accepts
exactly one argument -- the request *Environment* -- and **SHOULD** return an indexed array containing
exactly four values:

- A valid HTTP *integer* status code in the range 100-599
- A reason phrase (may be an empty string)
- An indexed array of string header lines
- A string, seekable stream resource or `Iterator` instance representing the response entity body

```php
$asgiApp = function(AsgiRequest $asgiRequest) {
    $status = 200;
    $reason = 'OK';
    $headers = [
        'Content-Type' => 'text/plain'
    ];
    $body = "Hello World";

    return [$status, $reason, $headers, $body];
};
```

While ASGI-compliant applications may optionally return more data beginning at index 4 of the response
array, it is not guaranteed to be supported across ASGI-compliant platforms. Servers **MAY**
also support responses that specify only an entity body, e.g.:

```php
<?php
$asgiApp = function(AsgiRequest $asgiRequest) {
    return '<html><body>Hello, World.</body></html>';
};
```

Servers accepting such responses are responsible for extrapolating the requisite headers from the
returned entity value. The acceptance and normalization of entity bodies is not required and may not
be portable across ASGI-compliant servers. Further discussion of the return array may be found in
the **RESPONSE** section.

## The Environment

The *Environment* **MUST** be an associative array specifying CGI-like keys as detailed below.
The *Application* is free to modify the *Environment* but **MUST** include at least the keys
documented in this section unless they would normally be empty. For example, an *Environment*
describing an HTTP request without an entity body **MUST NOT** specify `CONTENT-LENGTH` or
`CONTENT-TYPE` keys.

When an environment key is described as a boolean, its value **MUST** conform to PHP's concept of
"truthy-ness". This means that NULL, an empty string and an integer 0 (zero) are all valid "falsy"
values. If a boolean key is not present, an application **MAY** treat this as boolean false.

The values for all CGI-like keys (those not prefixed with "ASGI_") **MUST** be of the string type.
This mandate also applies to "numeric" values such as port numbers or content-length values.

### CGI KEYS

##### SERVER_NAME

The host/domain name specified by the client request stripped of any postfixed port numbers. Hosts
without a DNS name should specify the server's IP address. Servers **MUST** ensure that this value
is sanitized and free from potential malicious influence from the client-controlled `Host` header.

##### SERVER_PORT

The public facing port on which the request was received.

##### SERVER_PROTOCOL

The HTTP protocol agreed upon for the current request, e.g. 1.0 or 1.1. This key consists only of
the numeric protocol version and **MUST NOT** include any prefixing such as "HTTP" or "HTTP/".

##### REMOTE_ADDR

The IP address of the remote client responsible for the current request. Applications should be
equipped to deal with both IPv4 and IPv6 addresses.

##### REMOTE_PORT

The numeric port number in use by the remote client when making the current request.

##### REQUEST_METHOD

The HTTP request method used in the current request, e.g. GET/HEAD/POST.

##### REQUEST_URI

The undecoded raw URI parsed from the HTTP request start line. This value corresponds to the *full*
URI shown here in brackets:

```
HTTP [GET http://mysite.com/path/to/resource] HTTP/1.1
```

This value is dependent upon the raw request submitted by the client. It may be a full absolute URI
as shown above but it may also contain only the URI path and query components.

##### REQUEST_URI_PATH

Contains *only* the undecoded raw path and query components from the request URI. This value differs
from the `REQUEST_URI` key in that it **MUST** only represent the URI path and query submitted in
the request even if the raw request start line specified a full absolute URI.

##### REQUEST_URI_SCHEME

The request's URI scheme: `"https"` if the connection is encrypted, `"http"` otherwise. Servers
**MUST** assign this value appropriately given the state of encryption on the client connections
used to complete this request-response cycle.

##### QUERY_STRING

The portion of the request URL that follows the ?, if any. This key **MAY** be empty, but **MUST**
always be present, even when empty.

##### CONTENT_TYPE

The request's MIME type, as specified by the client. The presence or absence of this key **MUST**
correspond to the presence or absence of an HTTP Content-Type header in the request.

##### CONTENT_LENGTH

The length of the request entity body in bytes. The presence or absence of this key **MUST**
correspond to the presence or absence of HTTP Content-Length header in the request.

### ASGI KEYS

##### ASGI_VERSION

The ASGI protocol version adhered to by the server generating the request environment

##### ASGI_INPUT

An open stream resource referencing to the request entity body (if present in the request).

##### ASGI_ERROR

An open stream resource referencing the server's error stream. This makes it possible for applications
to centralize error logging in a single location.

##### ASGI_NON_BLOCKING

`TRUE` if the server is invoking the application inside a non-blocking event loop.

### HTTP_* KEYS

These keys correspond to the client-supplied HTTP request headers. The presence or absence of these
keys should correspond to the presence or absence of the appropriate HTTP header in the request. The
key is obtained converting the HTTP header field name to upper case, replacing all occurrences of
hyphens `(-)` with underscores `(_)` and prepending `HTTP_`, as in RFC 3875.

If a client sends multiple header lines with the same key, the server **MAY** treat them as if they
were sent in one line and combine them using commas `(,)` as specified in RFC 2616. Servers are not,
however, required to combine multiple header lines as some applications require the separation of
such fields (specifically, the `Set-Cookie` header). As a result, valid `HTTP_*` keys may contain either
a single string value or a one-dimensional numerically indexed array of strings representing each
individual header line.

###### ENVIRONMENT EXAMPLE

An example of a typical *Environment* array follows:

```php
$asgiRequest = [
    'SERVER_NAME'        => 'mysite.com',
    'SERVER_PORT'        => '80',
    'SERVER_PROTOCOL'    => '1.1',
    'REMOTE_ADDR'        => '123.456.789.123',
    'REMOTE_PORT'        => '9382',
    'REQUEST_METHOD'     => 'GET',
    'REQUEST_URI'        => '/hello_world.php?foo=bar',
    'REQUEST_URI_PATH'   => '/hello_world.php?foo=bar',
    'REQUEST_URI_SCHEME' => 'http',
    'QUERY_STRING'       => '?foo=bar',
    'CONTENT_TYPE'       => 'text/plain',
    'CONTENT_LENGTH'     => '42',

    // --- ASGI_* KEYS --- //

    'ASGI_VERSION'          => '0.1',
    'ASGI_INPUT'            => NULL,
    'ASGI_ERROR'            => $resource,
    'ASGI_NON_BLOCKING'     => TRUE,
    'ASGI_LAST_CHANCE'      => TRUE,

    // --- HTTP_* KEYS --- //

    'HTTP_HOST'             => 'mysite.com',
    'HTTP_CONNECTION'       => 'keep-alive',
    'HTTP_ACCEPT'           => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'HTTP_USER_AGENT'       => 'Mozilla/5.0 (X11; Linux x86_64) ...',
    'HTTP_ACCEPT_ENCODING'  => 'gzip,deflate,sdch',
    'HTTP_ACCEPT_LANGUAGE'  => 'en-US,en;q=0.8',
    'HTTP_ACCEPT_CHARSET'   => 'ISO-8859-1,utf-8;q=0.7,*;q=0.3',
    'HTTP_COOKIE'           => 'var1=value1&var2=value2';
];
```


## Application Response

Applications **SHOULD** return a response as an indexed four element array. The array indexes **MUST** be
ordered from 0 to 4 and associative keys **MUST NOT** be used. The response array consists of the following
elements:

##### STATUS

An HTTP status code. This **MUST** be a scalar value that, when cast as an integer, has a value greater
than or equal to 100, less than or equal to 599. Status codes **SHOULD** be used to reflect the semantic
meaning of the HTTP status codes documented in RFC 2616 section 10.

##### REASON

The reason **MAY** be an empty string or `NULL` value and **SHOULD** be an HTTP reason phrase as documented
in RFC 2616. The specification of the reason phrase is explicitly separated from the numeric status
code to simplify server processing of responses by their status.

##### HEADERS

The headers **MUST** be an associative array of key/value pairs. Header keys are case-insensitive and
may be normalized by servers without altering their meaning. All header keys must conform to the
ABNF rules specified in RFC 2616 section 4.2.

The value of each header key **MUST** contain either:

1. A defined scalar (not `NULL`), or
2. A numerically indexed single dimensional array containing defined scalar values

Any scalar header values **MUST** adhere to the ABNF rules specified in RFC 2616 section 4.2.

In the event of an array header value, each value **MUST** be sent by a server to the client
individually (e.g. multiple `Set-Cookie` headers).

Applications **SHOULD** endeavor to populate `Content-Type` key and, if known, the `Content-Length`
key. Servers **MAY** -- but are not required to -- normalize and/or correct invalid header values.

##### BODY

The response body **MUST** be returned from the application as any one of the following:

- A `NULL` value
- A string (possibly empty)
- An open PHP stream resource

`NULL`/string entity bodies are self-explanatory and should be returned directly to the client.
Entity bodies returned in stream resource form **MUST** be seekable to allow servers the opportunity
to add/correct missing `Content-Length` headers prior to sending the entity.
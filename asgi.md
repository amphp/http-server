### APPLICATION

An ASGI application (the *Application*) is a reference to a PHP callable. This callable accepts
*at least* one argument, the *Environment*, and **MUST** return an indexed array containing exactly
four values:

- A valid HTTP status code in the range 100-599
- A reason phrase (may be an empty string)
- An associative array of header values
- A string, stream resource or `Iterator` representing the response entity body

```php
$asgiApp = function(array $asgiEnv) {
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
array, it is not guaranteed to be supported across ASGI-compliant server platforms. Further discussion
of the return array may be found in the **RESPONSE** section.

### ENVIRONMENT

The *Environment* **MUST** be an associative array specifying CGI-like headers (as detailed below).
The *Application* is free to modify the *Environment*. The *Environment* **MUST** include these keys
(adopted from PEP 333 and PSGI) unless they would normally be empty. For example, an *Environment*
describing an HTTP request with no entity body is not required to specify `CONTENT-LENGTH` or
`CONTENT-TYPE` keys.

When an environment key is described as a boolean, its value **MUST** conform to PHP's concept of truthy-ness.
This means that an empty string or an integer 0 are both valid false values. If a boolean key is not
present, an application MAY treat this as a false value.

The values for all CGI keys (those not prefixed with "ASGI_") **MUST** be of the string type. This mandate
also applies to "numeric" values such as port numbers or content-length values.

###### CGI KEYS

- 'SERVER_NAME'

The host/domain name specified by the client request stripped of any postfixed port numbers. Hosts
without a DNS name should specify the server's IP address.

- 'SERVER_PORT'

The public facing port on which the request was received.

- 'SERVER_PROTOCOL'

The protocol agreed upon for the current request e.g. 1.0 or 1.1

- 'REMOTE_ADDR'

The IP address of the remote client responsible for the current request

- 'REMOTE_PORT'

The port number in use by the remote client when making the current request

- 'REQUEST_METHOD'

The HTTP request method used in the current request e.g. GET/HEAD/POST. This value **MUST NOT** be
an empty string; it is always required.

- 'REQUEST_URI'

The undecoded, raw request URL line. It is the raw URI path and query part that appears in the
HTTP <code>GET /... HTTP/1.x</code> line and does not contain URI scheme and host names. This value
**MUST NOT** be an empty string; it is always required.

- 'QUERY_STRING'

The portion of the request URL that follows the ?, if any. This key **MAY** be empty, but **MUST**
always be present, even if empty.

- 'CONTENT_TYPE'

The request's MIME type, as specified by the client. The presence or absence of this key **SHOULD**
correspond to the presence or absence of HTTP Content-Type header in the request.

- 'CONTENT_LENGTH'

The length of the content in bytes. The presence or absence of this key **SHOULD** correspond to the
presence or absence of HTTP Content-Length header in the request.

###### ASGI KEYS

- 'ASGI_VERSION'

*@todo*

- 'ASGI_URL_SCHEME'

The HTTP URL scheme for this request: `"https"` if the connection is encrypted, `"http"` otherwise.

- 'ASGI_INPUT'

An open stream resource referencing to the request entity body (if present in the request).

- 'ASGI_ERROR'

An open stream resource referencing the server's error stream. This makes it possible for applications
to centralize error logging in a single location.

- 'ASGI_NON_BLOCKING'

`TRUE` if the server is calling the application in a non-blocking event loop.

- 'ASGI_LAST_CHANCE'

`TRUE` if this is the final time the server expects to notify a handler of the current request.

###### HTTP_* KEYS

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
$asgiEnv = [
    'SERVER_NAME'        => 'mysite.com',
    'SERVER_PORT'        => '80',
    'SERVER_PROTOCOL'    => '1.1',
    'REMOTE_ADDR'        => '123.456.789.123',
    'REMOTE_PORT'        => '9382',
    'REQUEST_METHOD'     => 'GET',
    'REQUEST_URI'        => '/hello_world.php?foo=bar',
    'QUERY_STRING'       => '?foo=bar',
    'CONTENT_TYPE'       => 'text/plain',
    'CONTENT_LENGTH'     => '42',
    
    // --- BEGIN ASGI-SPECIFIC KEYS --- //
    
    'ASGI_VERSION'       => '0.1',
    'ASGI_URL_SCHEME'    => 'http',
    'ASGI_INPUT'         => NULL,
    'ASGI_ERROR'         => $resource,
    'ASGI_NON_BLOCKING'  => TRUE,
    'ASGI_LAST_CHANCE'   => TRUE,
    
    // --- BEGIN HTTP_* KEYS --- //
    
    'HTTP_HOST' => '127.0.0.1:1337',
    'HTTP_CONNECTION' => 'keep-alive',
    'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) ...',
    'HTTP_ACCEPT_ENCODING' => 'gzip,deflate,sdch',
    'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
    'HTTP_ACCEPT_CHARSET' => 'ISO-8859-1,utf-8;q=0.7,*;q=0.3',
    'HTTP_SET_COOKIE'   => [
        'cookie1',
        'cookie2',
        'cookie3'
    ];
];
```


### RESPONSE

Applications **MUST** return a response as an indexed four element array. The array indexes **MUST** be
ordered from 0 to 4 and associative keys **MUST NOT** be used. The response array consists of the following
elements:

###### STATUS

An HTTP status code. This **MUST** be a scalar value that, when cast as an integer, has a value greater
than or equal to 100, less than or equal to 599. Status codes **SHOULD** used to reflect the semantic
meaning of the HTTP status codes documented in RFC 2616 section 10.

###### REASON

The reason **MAY** be an empty string or `NULL` value and **SHOULD** be an HTTP reason phrase as documented
in RFC 2616. The specification of the reason phrase is explicitly separated from the numeric status
code to simplify server processing of responses by their status.

###### HEADERS

The headers **MUST** be an associative array of key/value pairs. Header keys are case-insensitive and
may be normalized by servers without altering their meaning. All header keys must conform to the
ABNF rules specified in RFC 2616 section 4.2.

The value of each header key **MUST** contain either:

1. A defined scalar (not `NULL`), or
2. A numerically indexed single dimensional array containing defined scalar values

Any scalar header values **MUST** adhere to the ABNF rules specified in RFC 2616 section 4.2.

In the event of an array header value, each value **MUST** be sent to the client separately (e.g.
multiple `Set-Cookie` headers).

Applications **SHOULD** endeavor to populate `Content-Type` key and, if known, the `Content-Length`
key. Servers may, but are not required to normalize and/or correct invalid header values.

###### BODY

The response body **MUST** be returned from the application as any one of the following:

- A `NULL` value
- A string (possibly empty)
- An open PHP stream resource
- An object instance implementing the `Iterator` interface

`NULL`/string entity bodies are self-explanatory and should be returned directly to the client.
Entity bodies returned in stream resource form **MUST** be seekable to allow servers the opportunity
to add/correct missing `Content-Length` headers prior to sending the entity. The prohibition on 
unseekable resource streams also prevents the use of custom stream wrappers to stream entity data
directly to clients. This decision is intenional and meant to enforce the use of `Iterator` body 
values as the method for streaming entities from server to client.

###### DELAYED RESPONSE AND STREAMING BODIES

In addition to strings and stream resources, all ASGI-compliant servers **MUST** support the output
of `Iterator` response entity bodies. `Iterator` bodies may be streamed to both HTTP/1.0 and HTTP/1.1
clients. HTTP/1.1 servers **MUST** chunk-encode the non-empty results of calls to `Iterator::current`
until `Iterator::valid` returns `FALSE` when responding to HTTP/1.1 clients. Streaming responses
**MUST** be accompanied by a `Connection: close` header when sent to HTTP/1.0 clients as the length
is not known when response output begins.






















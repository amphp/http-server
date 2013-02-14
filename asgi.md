### APPLICATION

An ASGI application (the *Application*) is a reference to a PHP callable. This callable accepts
*at least* one argument, the *Environment*, and `MUST` return an indexed array containing exactly
four values:

- A valid HTTP status code in the range 100-599
- A reason phrase (may be an empty string)
- An assiative array of header values
- A string, stream resource or `Iterator` representing the response entity body

```php
$asgiApp = function(array $asgiEnv) {
    return [
        200,
        'OK'
        ['Content-Type' => 'text/plain' ],
        "Hello World"
    ];
};
```

Further discussion of the return array may be found in the **RESPONSE** section.

### ENVIRONMENT

The *Environment* `MUST` be an associative array specifying CGI-like headers (as detailed below).
The *Application* is free to modify the *Environment*. The *Environment* `MUST` include these keys
(adopted from PEP 333 and PSGI) unless they would normally be empty. For example, an *Environment*
describing an HTTP request with no entity body is not required to specify `CONTENT-LENGTH` or
`CONTENT-TYPE` keys.

When an environment key is described as a boolean, its value `MUST` conform to PHP's concept of truthy-ness.
This means that an empty string or an integer 0 are both valid false values. If a boolean key is not
present, an application MAY treat this as a false value.

The values for all CGI keys (those not prefixed with "ASGI_") MUST be a scalar string. This also
applies to "numeric" values such as port numbers or content-length values.

###### CGI KEYS

- 'SERVER_SOFTWARE'
*@todo*

- 'SERVER_NAME'
*@todo*

- 'SERVER_PORT'
*@todo*

- 'SERVER_PROTOCOL'
*@todo*

- 'REMOTE_ADDR'
*@todo*

- 'REMOTE_PORT'
*@todo*

- 'REQUEST_METHOD'
*@todo*

- 'REQUEST_URI'
*@todo*

- 'QUERY_STRING'
*@todo*

- 'SCRIPT_NAME'
*@todo*

- 'PATH_INFO'
*@todo*

- 'CONTENT_TYPE'
*@todo*

- 'CONTENT_LENGTH'
*@todo*

###### ASGI KEYS

- 'ASGI_VERSION'
*@todo*

- 'ASGI_URL_SCHEME'
*@todo*

- 'ASGI_INPUT'
*@todo*

- 'ASGI_ERROR'
*@todo*

- 'ASGI_NON_BLOCKING'
*@todo*

- 'ASGI_LAST_CHANCE'
*@todo*

###### ENVIRONMENT EXAMPLE

An example of a typical *Environment* array follows:

```php
$asgiEnv = [
    'SERVER_SOFTWARE'    => 'AwesomeServer/1.0',
    'SERVER_NAME'        => 'mysite.com',
    'SERVER_PORT'        => '80',
    'SERVER_PROTOCOL'    => '1.1',
    'REMOTE_ADDR'        => '123.456.789.123',
    'REMOTE_PORT'        => '9382',
    'REQUEST_METHOD'     => 'GET',
    'REQUEST_URI'        => '/hello_world.php?foo=bar',
    'QUERY_STRING'       => '?foo=bar',
    'SCRIPT_NAME'        => 'hello_world.php',
    'PATH_INFO'          => '/',
    'CONTENT_TYPE'       => 'text/plain',
    'CONTENT_LENGTH'     => '42',
    
    // --- BEGIN ASGI-SPECIFIC KEYS --- //
    
    'ASGI_VERSION'       => '0.1',
    'ASGI_URL_SCHEME'    => 'http',     // The URL scheme ("https" if the connection is encrypted, "http" otherwise)
    'ASGI_INPUT'         => NULL,       // The temporary filesystem path to the entity body or a direct stream resource reference
    'ASGI_ERROR'         => $resource   // An open stream resource to which applications may write errors
    'ASGI_NON_BLOCKING'  => TRUE,       // TRUE if the server is calling the application in a non-blocking event loop.
    'ASGI_LAST_CHANCE'   => TRUE        // TRUE if this is the final time a handler will be notified of the current request
];
```

### RESPONSE

Applications `MUST` return a response as an indexed four element array. The array indexes `MUST` be
ordered from 0 to 4 and associative keys `MUST NOT` be used. The response array consists of the following
elements:

###### Status

An HTTP status code. This `MUST` be a scalar value that, when cast as an integer, has a value greater
than or equal to 100, less than or equal to 599. Status codes `SHOULD` used to reflect the semantic
meaning of the HTTP status codes documented in RFC 2616 section 10.

###### Reason

The reason `MAY` be an empty string or NULL value and `SHOULD` be an HTTP reason phrase as documented
in RFC 2616. The specification of the reason phrase is explicitly separated from the numeric status
code to simplify server processing of responses by their status.

###### Headers

The headers `MUST` be an associative array of key/value pairs. Header keys are case-insensitive and
may be normalized by servers without altering their meaning. All header keys must conform to the
ABNF rules specified in RFC 2616 section 4.2.

The value of each header key `MUST` contain either:

1. A defined scalar (not NULL), or
2. A numerically indexed single dimensional array containing defined scalar values

Any scalar header values `MUST` adhere to the ABNF rules specified in RFC 2616 section 4.2.

In the event of an array header value, each value `MUST` be sent to the client separately (e.g.
multiple `Set-Cookie` headers).

Applications `SHOULD` endeavor to populate `Content-Type` key and, if known, the `Content-Length`
key.

###### Body

The response body `MUST` be returned from the application as any one of the following:

- A NULL value
- A string (possibly empty)
- An open PHP stream resource
- An object instance implementing the `Iterator` interface

The body `MUST` be encoded into appropriate encodings and MUST NOT contain wide characters (> 255).



@todo discuss each body type






















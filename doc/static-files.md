Aerys: Static Files
===============

> **[Table of Contents](#table-of-contents)**

## Serving Static Files

Aerys exposes fully HTTP/1.1-compliant static file serving capabilities. Highlights include:

* Stream media with  partial and multipart `byte-range` responses
* Minimize bandwidth usage and response time via caching/precondition headers 
* Customize document root settings for each individual host in your server
* Streamline file serving behind dynamic application logic via `Sendfile:` headers
* Expose turnkey websocket applications; serve websocket HTML and Javascript files with no need for proxies or additional servers


> **NOTE**
> 
> Though no extension is *required* to use Aerys's static serving capabilities, production environments *SHOULD* deploy the `php-uv` extension. Native PHP cannot serve static files in a non-blocking way on its own. Aerys uses `php-uv` to service static file requests without blocking the server's event loop when available.


## Configuring the Document Root

Static document root settings are defined on a per-host basis using  `Aerys\Host` instances in the server configuration file. Hosts expose the following method to add static file serving on a domain:

```
Host::setRoot(string $pathToMyFiles, array $options = []);
```

The following simple aerys configuration serves static files from the `/path/to/my/documents` directory for the *mysite.com* host:

```php
<?php // Serve static files on mysite.com port 80
use Aerys\Host;

$mySite = new Host;
$mySite->setName('mysite.com');
$mySite->setRoot('/path/to/my/documents');
```

That's it! We now have a fully-functioning static file server listening on port 80 for requests to *mysite.com*. Any requests will be served from the path specified in our `Host::setRoot()` call. Of course, we'll generally want to serve some dynamic HTTP resource endpoints in addition to our static files. Lets look at a simple example of how static file serving interacts with routed HTTP application endpoints:

```php
<?php // Serve dynamic, websocket and static endpoints
use Aerys\Host;

$mySite = new Host;
$mySite->setName('mysite.com');
$mySite->setRoot('/path/to/my/documents');
$mySite->addRoute('GET', '/dynamic', function() { return 'hello world'; });
$mySite->addWebsocket('/mywebsocket', 'MyWebsocketClass');
```

The above configuration aerys routes requests to `/mywebsocket` to our specified websocket handler. `GET` requests to `http://mysite.com/dynamic` are routed to the "hello world" handler. All other requests are served from our static document root  at `/path/to/my/documents`.

> **NOTE**
> 
> The order in which routes/websockets/static files are specified in the configuration file has no effect on routing order. Dynamic routes *always* take precedence over static files in the event of a routing conflict. This means your static document root will *only* be used if a dynamic endpoint for the requested path does not exist.


### Assigning Options

The `$options` parameter allows users to fine-tune the behavior of their static file resources as shown here:

```php
<?php // Server config customizing static serving options
use Aerys\Host;

$mySite = new Host;
$mySite->setName('mysite.com');
$mySite->setRoot('/path/to/files', $options = [
	'indexes'       => ['index.html', 'index.htm'],
	'expiresPeriod' => 3600,
]);
```


### Option Reference

The following document root option keys (case-insensitive) are available in the `Host::setRoot()` options array.

Option Key              | Description            | Default
----------------------- | ---------------------- | --------------
| aggressiveCacheHeaderEnabled | If enabled, send `post-check`, `pre-check`, and `max-age` extensions as part of resource `Cache-Control:` headers | false |
| aggressiveCacheMultiplier | If `aggressiveCacheHeaderEnabled` is enabled, this value is multiplied by `expiresPeriod` to assign the `Cache-Control:` post-check value. | 0.9 |
| cacheTtl              | The number of seconds to cache filesystem stat results, open file descriptors and buffered file contents | 10 |
| cacheMaxBuffers<sup>†</sup>      | The maximum number of files that will be buffered in memory at any given time. | 50 |
| cacheMaxBufferSize<sup>†</sup>   | The maximum file size above which request file contents will no longer be buffered in-memory | 524288 |
| defaultCharset        | The default character set for text mime types | utf-8 |
| defaultMimeType       | The default mime type to send if no matching extension found in the `mimeTypes` or `mimeFile` setting | text/plain |
| expiresPeriod         | The number of seconds until HTTP caches should consider their resource representations stale | 3600 |
| indexes               | An optional list of directory index filenames to display on requests for a directory path | index.html |
| mimeFile				| An optional file specifying extension to mime type mappings | etc/mime |
| mimeTypes             | An key-value array mapping file extensions to mime types | n/a |
| private               | If this boolean option is enabled files in the document root can only be served when application endpoints specify a `Sendfile:` response header | false |
| useEtagInode          | Should file inodes be used when generating `Etag:` headers | true |



> **<sup>†</sup>** cached file content buffers are NOT shared across server process instances. The maximum memory exposure from cached file buffers at any one time grows linearly with the number of concurrent server processes. The use of cached content buffers may be disabled by setting `"cacheMaxBuffers" => 0`.


## Features and Considerations

### Sendfile

Applications often need to serve static file system resources with some additional logic or processing. Or perhaps only authenticated users should have access to static files. For such use-cases Aerys provides the `Sendfile:` header.

Any host that specifies a document root can use the `Sendfile` header to directly relay a files contents. For example:

```php
<?php
use Aerys\Host;

$host = new Host('mysite.com');
$host->setRoot('/path/to/files');

// GET /myfunction -> /path/to/files/file1.html
$host->addRoute('GET', '/myfunction', function($request) {
    // some application logic here
    return ['header' => 'Sendfile: file1.html'];
});

// GET /mygenerator -> /path/to/files/file2.html
$host->addRoute('GET', '/mygenerator', function($request) {
    yield 'header' => 'Sendfile: /file2.html';
});
```


### Designing for Scalability

When planning a naming scheme applications should generally expose static files under a separate subdomain from dynamic resources. Why? There are a couple of reasons ...

1. Browsers will only open a limited number of TCP connections on a per host-name basis when utilizing the HTTP/1.1 protocol<sup>†</sup>. By serving static files from a separate subdomain browsers will retrieve static resources at the same time as dynamic resources resulting in faster page loads.
2. Decoupling static resources from an application's primary domain name makes it much easier to move to a CDN or technology optimized for static file serving in the future. If, for example, all static files are served from `static.mysite.com` an application can simply modify a DNS entry to point this subdomain to a new IP when migrating file serving to new locations.

Aerys makes adding multiple host names trivial. Here's an example demonstrating how to serve dynamic resources from one domain while serving static resources from another in the same server instance:

```
<?php // Multi-domain static file server
use Aerys\Host;

// Main domain
$mySite = new Host;
$mySite->setName('mysite.com');
$mySite->addRoute('GET', '/', function($request) {
	$img = 'http://static.mysite.com/my-image.jpg';
    return "<html><body><img src="{$img}"/></body></html>";
});

// Define a subdomain to host static files
$subdomain = new Host;
$subdomain->setName('static.mysite.com');
$subdomain->setRoot('/path/to/my/files');
```

> **<sup>†</sup>** Though drafts for the future HTTP/2.0 (h2) protocol help mitigate this situation by multiplexing requests over a single TCP connection, HTTP/1.1 will be around for decades to come.

### Stat Caching

Compared to most CPU-bound operations, IO-bound operations like filesystem accesses are *slow*. Moreover, static file attributes are unlikely to change over short time frames. To improve static file serving performance Aerys caches the results of file `stat()` operations for a limited amount of time. The time-to-live (TTL) for these cache entries is subject to the `cacheTtl` option setting when invoking `Host::setRoot()`. By default, cached file stats persist for ten seconds.

> **NOTE**
> 
> When running a server in debug mode a force-refresh in your browser will refresh any cached `stat()` results for the resources to shorten the development feedback loop.

### Buffer Caching

To improve performance Aerys temporarily buffers the contents of small files in-memory to avoid hitting the filesystem on subsequent requests for the same resource. Buffer caching functionality is governed by three options:

1. `cacheTtl`
2. `cacheMaxBuffers`
3. `cacheMaxBufferSize`

When a file is requested whose size (in bytes) is less than the `cacheMaxBufferSize` setting the server will buffer it in memory for `cacheTtl` seconds. If the number of cached buffers reaches `cacheMaxBuffers` no new files will be buffered until TTL timeouts result in the removal of previously buffered entries from the cache.

An example configuration modifying these settings is shown here for reference:

```
<?php
use Aerys\Host;

$host = new Host('mysite.com');
$host->setRoot('/path/to/my/files', $options = [
    'cacheTtl'           => 5,     // seconds until cache entry is stale
    'cacheMaxBuffers'    => 25,    // don't cache more than 25 files at once
    'cacheMaxBufferSize' => 32768, // only buffer a file if smaller than 32kb
]);
```

----------------------------------------------

## Table of Contents

[TOC]
<?php

/**
 * examples/file_server.php
 * 
 * Aerys comes with a built-in filesystem request handler for serving static files from a specified
 * document root. Caching headers are attached to all responses sent and all valid HTTP/1.1
 * conditional headers are supported when responding to requests:
 * 
 * If-Match
 * If-Modified-Since
 * If-None-Match
 * If-Range
 * If-Unmodified-Since
 * 
 * Additionally, the Filesys handler fully supports byte range requests via the `Range:` request
 * header. Multiple byte ranges are also supported using the `multipart/byteranges` content type.
 * 
 * To use this example, simply execute the file using your system's php binary:
 * 
 * $ php file_server.php
 * 
 * Once the server has started, request http://127.0.0.1:1337/ in your browser or client of choice.
 */

require dirname(__DIR__) . '/autoload.php';

date_default_timezone_set('GMT');

(new Aerys\ServerFactory)->createServer([[
    'listen'  => '*:1337',
    'name'    => '127.0.0.1',
    'handler' => new Aerys\Handlers\Filesys(__DIR__ . '/file_server_root')
]])->listen();


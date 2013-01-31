<?php

/**
 * examples/file_server.php
 * 
 * Aerys comes with a built-in filesystem handler for serving static files from a specified
 * document root. This feature is more useful as a secondary host in a multi-host environment, but
 * this example is helpful nonetheless.
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


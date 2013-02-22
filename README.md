# AERYS

HTTP/1.1 webserver written in PHP. Awesomeness ensues. See `./examples` directory for more.

###### HELLO WORLD

```php
<?php
require __DIR__ . '/autoload.php';

$handler = function(array $asgiEnv) {
    $status = 200;
    $reason = 'OK';
    $headers = [];
    $body = '<html><body><h1>Hello, world.</h1></body></html>';
    
    return [$status, $reason, $headers, $body];
};

(new Aerys\Http\HttpServerFactory)->createServer([[
    'listen'  => '127.0.0.1:1337',
    'handler' => $handler
]])->listen();

```

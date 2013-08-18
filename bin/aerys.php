<?php

/**
 * Aerys HTTP Server
 * 
 * Example Usage:
 * ==============
 * 
 * php aerys.php --config /path/to/server/config.php
 * php aerys.php -b *:80 -d /path/to/document/root
 * php aerys.php --bind *:80 --name mysite.com --docroot /path/to/document/root
 * 
 * Options:
 * ========
 * 
 * -c, --config     Use a config file to bootstrap the server
 * -b, --bind       (required) The server's address and port (e.g. 127.0.0.1:1337 or *:1337)
 * -n, --name       Optional host (domain) name
 * -d, --docroot    The filesystem directory from which to serve static files
 * -h, --help       Display help screen
 */

require dirname(__DIR__) . '/autoload.php';

try {
    $bootstrapper = new Aerys\Config\Bootstrapper;
    if ($config = $bootstrapper->loadConfigFromCommandLine()) {
        list($reactor, $server) = $bootstrapper->createServer($config);
        $server->start();
        $reactor->run();
    } else {
        $bootstrapper->displayHelp();
    }
} catch (Aerys\Config\ConfigException $e){
    echo "\nConfig error: ", $e->getMessage(), "\n", $bootstrapper->displayHelp();
} catch (Exception $e) {
    echo $e, "\n";
}

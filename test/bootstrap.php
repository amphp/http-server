<?php

define('TEST_DIR', __DIR__);
define('FIXTURE_DIR', __DIR__ . '/fixture');
define('INTEGRATION_SERVER_ADDR', '127.0.0.1:1500');

// Derick Rethans hates good design.
date_default_timezone_set('UTC');

// Make sure we see errors
error_reporting(E_ALL);

// Autoloader for Aerys libs
require dirname(__DIR__) . '/autoload.php';

// Autoloader for Artax client (used for integration testing)
require dirname(__DIR__) . '/vendor/Artax/autoload.php';

// Autoloader for vfsStream's ridiculous source directory structure (for mocking the filesystem)
spl_autoload_register(function($class) {
    if (0 === strpos($class, 'org\\bovigo\\vfs\\')) {
        $class = str_replace('\\', DIRECTORY_SEPARATOR, $class);
        $file = dirname(__DIR__) . '/vendor/vfsStream/src/main/php/' . $class . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});

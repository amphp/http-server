<?php

define('FIXTURE_DIR', __DIR__ . '/fixture');

require dirname(__DIR__) . '/autoload.php';

// Autoloader for vfsStream's ridiculous source directory structure
spl_autoload_register(function($class) {
    if (0 === strpos($class, 'org\\bovigo\\vfs\\')) {
        $class = str_replace('\\', DIRECTORY_SEPARATOR, $class);
        $file = dirname(__DIR__) . '/vendor/vfsStream/src/main/php/' . $class . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});

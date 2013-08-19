<?php

define('FIXTURE_DIR', __DIR__ . '/fixture');

// Derick Rethans hates good design
date_default_timezone_set('UTC');

// Make sure we see errors
error_reporting(E_ALL);

// Autoloader for Aerys libs
require __DIR__ . '/../vendor/autoload.php';

// Autoloader for Aerys test classes
spl_autoload_register(function($class) {
    if (0 === strpos($class, 'Artax\\Test\\')) {
        $class = str_replace('\\', DIRECTORY_SEPARATOR, $class);
        $file = __DIR__ . '/Aerys/Test/' . $class . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});

// Autoloader for Artax client (used for integration testing)
require __DIR__ . '/../vendor/Artax/autoload.php';

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

require __DIR__ . '/Aerys/Test/SingleByteWriteFilter.php';
stream_filter_register("single_byte_write", "Aerys\Test\SingleByteWriteFilter");

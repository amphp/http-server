<?php

require __DIR__ . '/vendor/Auryn/autoload.php';
require __DIR__ . '/vendor/Alert/autoload.php';

spl_autoload_register(function($class) {
    if (0 === strpos($class, 'Aerys\\')) {
        $class = str_replace('\\', DIRECTORY_SEPARATOR, $class);
        $file = __DIR__ . "/src/$class.php";
        if (file_exists($file)) {
            require $file;
        }
    }
});

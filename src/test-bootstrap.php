<?php

require __DIR__ . '/bootstrap.php';

spl_autoload_register(function($class) {
    if (strpos($class, 'AerysTest\\') === 0) {
        $name = substr($class, strlen('AerysTest'));
        require __DIR__ . "/../test" . strtr($name, '\\', DIRECTORY_SEPARATOR) . '.php';
    }
});

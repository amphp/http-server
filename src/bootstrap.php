<?php

require __DIR__ . '/../vendor/Auryn/src/bootstrap.php';
require __DIR__ . '/../vendor/Alert/src/bootstrap.php';
//require __DIR__ . '/../vendor/Amp/src/bootstrap.php';
require_once __DIR__ . '/../vendor/FastRoute/src/bootstrap.php';

spl_autoload_register(function($class) {
    if (strpos($class, 'Aerys\\') === 0) {
        $name = substr($class, strlen('Aerys'));
        require __DIR__ . "/../lib" . strtr($name, '\\', DIRECTORY_SEPARATOR) . '.php';
    } elseif (strpos($class, 'Amp\\') === 0) {
        $name = substr($class, strlen('Amp'));
        require __DIR__ . "/../vendor/Amp/lib" . strtr($name, '\\', DIRECTORY_SEPARATOR) . '.php';
    } elseif (strpos($class, 'FastRoute\\') === 0) {
        $name = substr($class, strlen('FastRoute'));
        require __DIR__ . "/../vendor/FastRoute/src" . strtr($name, '\\', DIRECTORY_SEPARATOR) . '.php';
    }
});
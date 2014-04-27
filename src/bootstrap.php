<?php

require_once __DIR__ . '/../vendor/FastRoute/src/bootstrap.php';

spl_autoload_register(function($class) {
    if (strpos($class, 'Aerys\\') === 0) {
        $name = substr($class, strlen('Aerys'));
        include __DIR__ . "/../lib" . strtr($name, '\\', DIRECTORY_SEPARATOR) . '.php';
    } elseif (strpos($class, 'Alert\\') === 0) {
        $name = substr($class, strlen('Alert'));
        include __DIR__ . "/../vendor/Alert/lib" . strtr($name, '\\', DIRECTORY_SEPARATOR) . '.php';
    } elseif (strpos($class, 'After\\') === 0) {
        $name = substr($class, strlen('After'));
        include __DIR__ . "/../vendor/After/lib" . strtr($name, '\\', DIRECTORY_SEPARATOR) . '.php';
    } elseif (strpos($class, 'Amp\\') === 0) {
        $name = substr($class, strlen('Amp'));
        include __DIR__ . "/../vendor/Amp/lib" . strtr($name, '\\', DIRECTORY_SEPARATOR) . '.php';
    } elseif (strpos($class, 'Auryn\\') === 0) {
        $name = substr($class, strlen('Auryn'));
        include __DIR__ . "/../vendor/Auryn/lib" . strtr($name, '\\', DIRECTORY_SEPARATOR) . '.php';
    } elseif (strpos($class, 'FastRoute\\') === 0) {
        $name = substr($class, strlen('FastRoute'));
        include __DIR__ . "/../vendor/FastRoute/src" . strtr($name, '\\', DIRECTORY_SEPARATOR) . '.php';
    }
});

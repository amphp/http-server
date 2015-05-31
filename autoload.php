<?php
// <hack>
// Hack to autoload correctly if installed via composer and not git
$dir = str_replace('\\', '/', __DIR__);
$path = 'vendor/amphp/aerys';
$autoloadPath = strpos($dir, $path) === strlen($dir) - strlen($path)
    ? __DIR__ . '/../../autoload.php'
    : __DIR__ . '/vendor/autoload.php';
require $autoloadPath;
// </hack>

<?php
// <hack>
// Hack to autoload correctly if installed via composer and not git
$dir = str_replace('\\', '/', __DIR__);
$autoloadPath = strpos($dir, 'vendor/amphp/aerys/')
    ? __DIR__ . '/../../autoload.php'
    : __DIR__ . '/vendor/autoload.php';
require $autoloadPath;
// </hack>

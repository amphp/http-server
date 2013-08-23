<?php

/**
 * This demo server shows how to use class instance methods to dynamically generate responses. If
 * you open the `Ex201_BasicRouting` class you'll see that it has a constructor dependency. Aerys
 * automatically instantiates and provisions your endpoint classes with needed dependencies subject
 * to definitions you can optionally supply (covered later).
 * 
 * Note that when specify class method route handlers the same construction is used whether you
 * specify instance methods or static methods:
 * 
 * - MyClass::myInstanceMethod
 * - MyClass::myStaticMethod
 * 
 * To run:
 * $ bin/aerys -c examples/ex201_basic_routing.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/support/Ex201_BasicRouting.php';

$app = new Aerys\Framework\App;

$app->addRoute('GET', '/', 'Ex201_BasicRouting::hello');
$app->addRoute('GET', '/info', 'Ex201_BasicRouting::info');
$app->addRoute('GET', '/$#arg1/$#arg2/$arg3', 'Ex201_BasicRouting::args');

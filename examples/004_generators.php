<?php

/**
 * @TODO
 *
 * RUNNABLE EXAMPLE
 *
 * To run this example server:
 *
 * $ bin/aerys -c examples/004_generators.php
 *
 * Once started, load http://127.0.0.1:1337/ or http://localhost:1337/ in your browser.
 */

require __DIR__ . '/support/004_includes.php';

$myHost = (new Aerys\Host)
    ->setPort(1337)
    ->setAddress('*')
    ->addRoute('GET', '/gen1', 'gen1')
    ->addRoute('GET', '/gen2', 'gen2')
    ->addRoute('GET', '/gen3', 'gen3')
    ->addRoute('GET', '/gen4', 'gen4')
    ->addRoute('GET', '/gen5', 'gen5')
    ->addRoute('GET', '/gen6', 'gen6')
    ->addRoute('GET', '/gen7', 'gen7')
    ->addRoute('GET', '/gen8', 'gen8')
    ->addRoute('GET', '/gen9', 'gen9')
    ->addRoute('GET', '/gen10', 'gen10')
    ->addRoute('GET', '/gen11', 'gen11')
    ->addResponder('myFallbackResponder')
;

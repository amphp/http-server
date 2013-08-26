<?php

/**
 * This demo server shows how to run a fully HTTP/1.1-compliant static file server without any
 * dynamic functionality. Pretty. Freaking. Easy. Note that if this is all you need from Aerys
 * you're probably better served to do the same thing using the command line binary:
 * 
 * $ aerys --docroot /path/to/static/files
 * 
 * To run:
 * $ bin/aerys -a examples/ex301_static_files.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = (new Aerys\Framework\App)->setDocumentRoot(__DIR__ . '/support/docroot');

<?php

/**
 * This demo server shows how to run a fully HTTP/1.1-compliant static file server without any
 * dynamic functionality. Pretty. Freaking. Easy. Note that if this is all you need from Aerys
 * you're probably better served to use the command line binary without a config file.
 *
 * To run:
 *
 *     $ bin/aerys -c examples/008_documents.php
 */

require_once  __DIR__ . '/../src/bootstrap.php';

$app = (new Aerys\Start\App);
$app->setPort(1338);
$app->setDocumentRoot(__DIR__ . '/support/docroot');

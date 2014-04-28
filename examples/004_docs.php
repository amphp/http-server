<?php

/**
 * @TODO Add explanation
 *
 * To run:
 *
 * $ bin/aerys -c examples/004_docs.php
 *
 * Once started, load http://127.0.0.1:1337/ or http://localhost:1337/ in your browser.
 */

$myFileServer = (new Aerys\HostConfig)
    ->setPort(1337)
    ->setDocumentRoot(__DIR__ . '/support/docroot')
;

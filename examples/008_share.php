<?php

/**
 * SHARED STORAGE
 *
 * Aerys allows applications to share data across requests in userland via the "AERYS_STORAGE" key
 * of the request environment array. The shared storage is a mutable StdClass instance that persists
 * for the lifetime of the worker thread/process.
 *
 * IMPORTANT
 *
 * The shared storage instance is not global to the entire application at all times. It is only
 * shared for requests in the same worker thread or process. At any given time the server may have
 * several functioning workers. It is the application's job to initialize properties on the shared
 * object as needed.
 *
 * RUNNABLE EXAMPLE
 *
 * To run this example server:
 *
 * $ bin/aerys -c examples/008_share.php
 *
 * Once started, load http://127.0.0.1:1337/ or http://localhost:1337/ in your browser.
 *
 * NOTE
 *
 * If you run this example and access the server using your browser you'll likely notice the
 * increment increasing by two instead of one on each page load. This is because your browser
 * is making an additional favicon request -- it's not an error :)
 */


namespace Aerys;

/* --- Global server options here --------------------------------------------------------------- */

const KEEP_ALIVE_TIMEOUT = 30;

/* --- http://localhost:1338/ or http://127.0.0.1:1338/  (all IPv4 interfaces) ------------------ */

$myHost = (new HostConfig)
    ->setPort(1337)
    ->setAddress('*')
    ->addResponder(function($request) {
        $storage = $request['AERYS_STORAGE'];
        if (empty($storage->increment)) {
            $i = $storage->increment = 1;
        } else {
            $i = ++$storage->increment;
        }

        return "<html><body><h1>Shared Storage</h1><p>Increment: {$i}</p></body></html>";
    })
;

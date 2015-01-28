<?php

namespace Aerys;

/**
 * A Host "template" that won't be registered with the server bootstrapper
 *
 * This class is useful if you wish to create a template Host to clone as a
 * baseline for multiple other hosts. HostTemplate instances are ignored by
 * the server at boot time.
 *
 * Example:
 *
 *     <?php
 *     // Create a template with common crypto settings
 *     $tpl = (new Aerys\HostTemplate)->setCrypto('/path/to/san/cert.pem');
 *
 *     // Clone the template as a base for two separate hosts
 *     (clone $tpl)->setName('mysite.com')->addResponder(...);
 *     (clone $tpl)->setName('static.mysite.com')->setRoot(...);
 *
 */
class HostTemplate extends Host {
    public function __construct() {
        // We specifically avoid calling the parent constructor here so
        // our template won't be registered with the server bootstrapper
        // and can be used as a basis for other host definitions.
    }

    public function __clone() {
        return parent::__clone();
    }
}

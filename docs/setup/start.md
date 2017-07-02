---
title: A first config file
description: Aerys is a non-blocking HTTP/1.1 and HTTP/2 application / websocket / static file server.
title_menu: Start
layout: tutorial
---

```php
<?php

(new Aerys\Host)->use(Aerys\root("/var/www/public_html"));
```

Save it as `config.php` and load it via `sudo php vendor/bin/aerys -d -c config.php`. [The `sudo` may be necessary as it binds by default on port 80 - for this case there is an [`user` option to drop the privileges](../options/common.html).]

That's all needed to serve files from a static root. Put an index.html there and try opening `http://localhost/` in the browser.

The Host instance is at the root of each virtual host served by Aerys. By default it serves your content over port 80 on localhost. To configure an alternative binding, have a look [here](../host/interface.html).

The `root($path)` function returns a handler for static file serving and expects a document root path to serve files from as first parameter.

> **Note**: Debug mode is most helpful when zend.assertions is set to 1. If it isn't set to 1 in your config, load the server with `php -d zend.assertions=1 vendor/bin/aerys -d -c config.php`.

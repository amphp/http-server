---
title: Redirecting Aerys Hosts
title_menu: Redirection
layout: tutorial
---

```php
(new Aerys\Host)
	->use(Aerys\router()->get('/', function (Aerys\Request $req, Aerys\Response $res) { $res->end("Access any other path on this host to be redirected!"); })
	->redirect("http://example.com/foo", 308)
;
```

`Aerys\Host::redirect(string $absoluteUri, int $statusCode = 307)` redirects to the location specified by `$absoluteUri . $req->getUri()`, if no other handler (set via [`use()`](use.html)) has started a response.

It boils down to a simple `Aerys\Response::setStatus($statusCode);` and `Aerys\Request::setHeader("location", $absoluteUri . $req->getUri());` then.
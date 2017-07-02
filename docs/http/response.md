---
title: Response Basics
title_menu: Response Basics
layout: tutorial
---

```php
(new Aerys\Host)->use(function(Aerys\Request $req, Aerys\Response $res) {
	# This is the default status code and does not need to be set explicitly
	# $res->setStatus(200);

	$res->setHeader("X-LIFE", "Very nice!");
	$res->end("With a bit text");
});
```

`Aerys\Response::setStatus($status)` sets the response status. The `$status` must be between 100 and 599. For reference see the [Wikipedia page on HTTP status codes](https://en.wikipedia.org/wiki/List_of_HTTP_status_codes).

`Aerys\Response::setHeader($header, $value)` sets a custom header, but be aware about header injections. Do not accept `\n` characters here if there will ever be user input! See also the [OWASP page on HTTP Response Splitting](https://www.owasp.org/index.php/HTTP_Response_Splitting).

`Aerys\Response::end($data = "")` terminates a response and sends the passed data. For more fine grained sending, have a look at [the guide about streaming](../http-advanced/streaming.html).

For a full explanation of all available methods check out the [`Response` class docs](../contents/classes/response.html).

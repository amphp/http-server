---
title: Request Basics in Aerys
title_menu: Request Basics
layout: tutorial
---

```php
(new Aerys\Host)->use(function(Aerys\Request $req, Aerys\Response $res) {
	# if the header not passed, null is returned
	$user_agent = $req->getHeader("User-Agent") ?? "";

	# Get a query string parameter
	$action = $res->getParam("action") ?? "default";

	$res->end("Action: <i>$action</i> requested by User-Agent<br><pre>$user_agent</pre>");
});
```

Try accessing `http://localhost/?action=beautiful` in the browser.

`Aerys\Request::getParam(string $parameter)` returns a string if the query string parameter was passed (if multiple ones with the same name exist, the first one), otherwise `null`.

`Aerys\Request::getHeader(string $name)` returns a headers value. If multiple headers with the same name exist, it will return the first value. If none exists, it will return `null`.

There is additional information available about the full request API, check out the [`Request` docs](../contents/classes/request.html).

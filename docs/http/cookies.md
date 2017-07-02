---
title: Cookies
title_menu: Cookies
layout: tutorial
---

```php
(new Aerys\Host)->use(function (Aerys\Request $req, Aerys\Response $res) {
	if (($date = $req->getCookie('tasty')) !== null) {
		if ($req->getParam('eat') !== null) {
			$res->setCookie("tasty", "", ["Expires" => date("r", 784111777)]); # somewhen in the past
			$res->end("Mhhhhhhhm. A veeeery tasty cookie from ".date("d.m.Y H:i:s", (int) $date)."!<br />
					   No cookie there now ... <a href=\"?set\">GET A NEW ONE!</a> or <a href=\"/\">Go back.</a>'");
		} else {
			$res->end("A tasty cookie had been produced at ".date("d.m.Y H:i:s", (int) $date));
		}
	} elseif ($req->getParam('produce') !== null) {
		$res->setCookie("tasty", time(), ["HttpOnly"]);
		$res->end('A tasty cookie was produced right now. <a href="/">Go back.</a>');
	} else {
		$res->end('No cookie availables yet ... <a href="?produce">GET ONE RIGHT NOW!</a>');
	}
});
```

`Aerys\Request::getCookie($name)` returns a string with the value of the cookie of that $name, or `null`.

`Aerys\Response::setCookie($name, $value, $flags = [])` sets a cookie with a given name and value.

Valid flags are per [RFC 6265](https://tools.ietf.org/html/rfc6265#section-5.2.1):

- `"Expires" => date("r", $timestamp)` - A timestamp when the cookie will become invalid (set to a date in the past to delete it)
- `"Max-Age" => $seconds` - A number in seconds when the cookie must be expired by the client
- `"Domain" => $domain` - The domain where the cookie is available
- `"Path" => $path` - The path the cookie is restricted to
- `"Secure"` - Only send this cookie to the server over TLS
- `"HttpOnly"` - The client must hide this cookie from any scripts (e.g. Javascript)

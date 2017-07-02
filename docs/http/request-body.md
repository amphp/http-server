---
title: Parsing Request Bodies in Aerys
title_menu: Parsing Bodies
layout: tutorial
---

```php
(new Aerys\Host)->use(function(Aerys\Request $req, Aerys\Response $res) {
	$body = yield Aerys\parseBody($req);
	$webserver = $body->get("webserver");

	if ($webserver === null) {
		$res->end('<form action="" method="post">Which one is the best webserver? <input type="text" name="webserver" /> <input type="submit" value="check" /></form>');
	} elseif (strtolower($webserver) == "aerys") {
		$res->end("Correct! Aerys is definitely the ultimate best webserver!");
	} else {
		$res->end("$webserver?? What's that? There is only Aerys!");
	}
});
```

`yield Aerys\parseBody($request, $size = 0)` expects an `Aerys\Request` instance and a maximum body size (there is [a configurable default](../performance/production.html)) as parameters and returns a [`ParsedBody`](../contents/classes/parsedbody.html) instance exposing a `get($name)` and a `getArray($name)`.

`get($name)` always returns a string (first parameter) or null if the parameter was not defined.

`getArray($name)` returns all the parameters with the same name in an array.

To get all the passed parameter names, use the `getNames()` method on the `ParsedBody` instance.

`getMetadata($name)` provides any metadata attached to a request parameter. There also is an `getMetadataArray($name)` function for an array with metadata of all parameters with the same name.

The metadata of a request consists of an array which may contain `"mime"` and `"filename"` keys if provided by the client (e.g. when uploading a file).
---
title: Upload handling in Aerys
title_menu: Handling Uploads
layout: tutorial
---

```php
(new Aerys\Host)->use(function(Aerys\Request $req, Aerys\Response $res) {
	$body = yield Aerys\parseBody($req, 200000 /* max 200 KB */);
	$file = $body->get("file");

	if ($file === null) {
		$res->end('<form action="" method="post">Upload a small avatar: <input type="file" name="file" /> <input type="submit" value="check" /></form>');
	} else {
		# in real world, you obviously need to first validate the filename against directory traversal and against overwriting...
		$name = $body->getMetadata("file")["filename"] ?? "<unnamed>";
		\Amp\file\put("files/$name", $file);
		$res->end("Got ".strlen($file)." bytes of data for a file named $name ... saved under files.");
	}
});
```

Generally uploads are just a normal field of the body you can grab with `get($name)`.

Additionally, uploads may contain some metadata: `getMetadata($name)` returns an array with the fields `"mime"` and `"filename"` (if the client passed these).

> **Warning**: Avoid setting the `$size` parameter on `parseBody()` very high, that may impact performance with many users accessing it. Check [the guide for larger parsed bodies](../performance/bodyparser.html) out if you want to do that.
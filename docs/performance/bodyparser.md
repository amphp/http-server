---
title: Incremental parsed body handling via BodyParser
title_menu: BodyParser
layout: tutorial
---

```php
(new Aerys\Host)->use(function(Aerys\Request $req, Aerys\Response $res) {
	try {
		$body = Aerys\parseBody($req);
		$field = $body->stream("field", 10 * 1024 ** 2); // 10 MB
		$name = (yield $field->getMetadata())["name"] ?? "<unknown>";
		$size = 0;
		while (yield $field->valid()) {
			$size += \strlen($field->consume()));
		}
		$res->end("Received $size bytes for file $name");
	} catch (Aerys\ClientException $e) {
		# Writes may still arrive, even though reading stopped
		$res->end("Upload failed ...")
		throw $e;
	}
});
```

Apart from implementing `Amp\Promise` (to be able to return `Aerys\ParsedBody` upon `yield`), the `Aerys\BodyParser` class (an instance of which is returned by the `Aerys\parseBody()` function) exposes one additional method:

`stream($field, $size = 0): Aerys\FieldBody` with `$size` being the maximum size of the field [the size is added to the general size passed to `Aerys\parseBody()`].

This returned `Aerys\FieldBody` instance extends `Aerys\Body` and thus has [the same semantics than it](body.html).

Additionally, to provide the metadata information, the `Aerys\FieldBody` class has a `getMetadata()` function to return [the metadata array](../http/request-body.html).

The `Aerys\BodyParser::stream()` function can be called multiple times on the same field name in order to fetch all the fields with the same name:

```php
# $body being an instance of Aerys\BodyParser
while (yield ($field = $body->stream("field"))->valid()) {
	# init next entry of that name "field"
	do {
		$data = $field->consume();
		# work on $data
	} while (yield $field->valid());
}
```
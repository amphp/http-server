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
		while (null !== ($data = yield $field->valid())) {
			$size += \strlen($data));
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

This returned `Aerys\FieldBody` instance extends `\Amp\ByteStream\Message` and thus has [the same semantics than it]](../../byte-stream/message) [@TODO bogus link, Amp\ByteStream\Message docs missing].

Additionally, to provide the metadata information, the `Aerys\FieldBody` class has a `getMetadata()` function to return [the metadata array](../http/request-body.html).

The `Aerys\BodyParser::stream()` function can be called multiple times on the same field name in order to fetch all the fields with the same name:

```php
# $body being an instance of Aerys\BodyParser
while (null !== $data = yield ($field = $body->stream("field"))->read()) {
	# init next entry of that name "field"
	do {
		# work on $data
	} while (null !== $data = yield $field->read());
}
```
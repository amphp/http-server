---
title: Middlewares in Aerys
title_menu: Middleware
layout: docs
---

* Table of Contents
{:toc}

Middlewares are a powerful tool to intercept requests and manipulate them with low-level access to the [`InternalRequest`](internalrequest.html) instance.

> **Warning**: We do not validate anything on the `InternalRequest` instance and objects only accessible through it from outside though. It's a value object with only public properties. It's your responsibility to not fuck up the objects and make things go bad. You can manipulate nearly everything request and client related here - and if you do, make attention to really know what you do.

> **Note**: These internal classes only accessible via the `InternalRequest` instance tend to be more volatile regarding their API. Even though we employ semver, we reserve the rights to break these APIs in minors (although not bugfix releases).

## `do(InternalRequest): \Generator|null`

The single method of the `Middleware` interface. It is called each time a request is being dispatched on a host or route this middleware is used on.

In case this method isn't returning a Generator, there's not much magic to it. Otherwise though:

One needs to first `yield` once, which will return the headers of the response in an associative array with the header names (the keys) being all lowercase. The value is an array of strings. (This is needed due to things like `set-cookie`, requiring multiple headers with the same name.)

Further yields will return you the data as a stream of strings, until null is returned to indicate the end of the stream. In between false may be returned, indicating that you should try to flush any data you are temporariliy buffering.

The first non-null yield must be the headers, then you may yield strings. It's also possible to return instead of yield, in order to finish remove this middleware from the request.

For internal processing there are a few pseudo-headers in response, namely `":status"` and `":reason"`.

## Example

Assuming the functions being methods of a class implementing `Middleware`.

```php
function do(Aerys\InternalRequest $ireq) {
	// add a dot after each byte when client specified X-INSERT-DOT header
	if (!empty($ireq->headers["x-insert-dot"][0])) { // header names are lowercase
		return; // no header, no processing
	}

	$headers = yield; // we may also manipulate $headers before we return it

	if ($headers[":status"] != 200) { // only bother with successful 200 OK requests
		return $headers; // at these points we stop the middleware processing
	}

	$data = yield $headers;
	do {
		$processed = implode(".", [-1 => ""] + str_split($data));
	} while (($data = yield $processed) !== null);
	/* yup, the yield may return false, but it's coerced to "" when used as string,
	 * so it doesn't matter here. */

	return "."; // and a final dot!
}
```

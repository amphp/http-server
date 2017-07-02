---
title: Pushing Data to Clients with WebSockets
title_menu: Pushing Data to Clients
layout: tutorial
---

```php
class MiniChat implements Aerys\Websocket {
	private $ws;

	public function onStart(Aerys\Websocket\Endpoint $endpoint) {
		$this->ws = $endpoint;
	}

	public function onHandshake(Aerys\Request $request, Aerys\Response $response) {
		// not important for now, can be used to check origin for example
	}

	public function onOpen(int $clientId, $handshakeData) {
		$this->ws->broadcast("Welcome new client $clientId!");
	}

	public function onData(int $clientId, Aerys\Websocket\Message $msg) {
		$text = yield $msg;
		$this->ws->send("<i>Message received ... Sending in 5 seconds ...</i>", $clientId);
		yield new Amp\Pause(5000);
		$this->ws->broadcast("Client $clientId said: $text");
	}

	public function onClose(int $clientId, int $code, string $reason) {
		$this->ws->broadcast("User with client id $clientId closed connection with code $code");
	}

	public function onStop() {
		// when server stops, not important for now
	}
}
```

```php
$router = Aerys\router()
	->get('/ws', Aerys\websocket(new MiniChat))

$root = Aerys\root(__DIR__ . "/public");

(new Aerys\Host)->use($router)->use($root);
```

```html
<!doctype html>
<script type="text/javascript">
var ws = new WebSocket("ws://localhost/ws");
document.write('<input id="in" /><input type="submit" id="sub" /><br />');

document.getElementById("sub").onClick(function() {
	ws.send(document.getElementById("in").value);
});

ws.onopen = function() {
	// crappy console.log alternative for example purposes
	document.writeln("opened<br />");
};

ws.onmessage = function(msg) {
	document.writeln(msg.data + "<br />");
};

ws.onerror = ws.onclose = function(e) {
	document.writeln(e);
};
</script>
```

The `Websocket\Endpoint` interface exposes two important functions: `send()` and `close()`. It is passed inside the `onStart` handler of the `Websocket` interface upon server startup.

`send($data, $clientId)` expects a string to send to a single client id given as second parameter.

`close($clientId, $code = Aerys\Websocket\Code::NORMAL_CLOSE, $reason = "")` closes a specific client connection. No further messages should be sent after it. Note that `onClose` is also called after a manual `close()`.

`broadcast($data, $exceptClientIds = [])` will send a string to every connected client. It is possible to specifically exclude some clients by passing an array of client ids to exclude as second parameter.

> **Note**: while it is possible to `send()` a same message to each client, it might be more efficient to just `multicast($data, $clientIds)` an array of clients.

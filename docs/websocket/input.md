---
title: WebSocket Input
permalink: /websocket/input
---

```php
# This example prints to STDOUT. Do that only for testing purposes!

class MyWs implements Aerys\Websocket {
    public function onStart(Aerys\Websocket\Endpoint $endpoint) {
        // $endpoint is for sending
    }

    public function onHandshake(Aerys\Request $request, Aerys\Response $response) {

    }

    public function onOpen(int $clientId, $handshakeData) {
        print "Successful Handshake for user with client id $clientId\n";
    }

    public function onData(int $clientId, Aerys\Websocket\Message $msg) {
        print "User with client id $clientId sent: " . yield $msg . "\n";
    }

    public function onClose(int $clientId, int $code, string $reason) {
        print "User with client id $clientId closed connection with code $code\n";
    }

    public function onStop() {
        // when server stops, not important for now
    }
}
```

```php
$router = Aerys\router()
    ->get('/ws', Aerys\websocket(new MyWs));

$root = Aerys\root(__DIR__ . "/public");

(new Aerys\Host)->use($router)->use($root);
```

```html
<!doctype html>
<script type="text/javascript">
var ws = new WebSocket("ws://localhost/ws");
ws.onopen = function() {
    // crappy console.log alternative for example purposes
    document.writeln("opened<br />");
    ws.send("ping");

    document.writeln("pinged<br />");
    ws.close();

    document.writeln("closed<br />");
};

ws.onerror = ws.onmessage = ws.onclose = function(e) {
    document.writeln(e);
};
</script>
```

Each connection is identified by an unique client id, which is passed to `onOpen()`, `onData()` and `onClose()`.

`onOpen($clientId, $handshakeData)` is called at the moment where the websocket connection has been successfully established (i.e. after the handshake has been sent). For `$handshakeData`, check the [Handshake handling](handshake.html) out.

`onData($clientId, $msg)` is called upon each received Websocket frame. At the time when `onData()` is called, the message may not yet have been fully received. Thus use `yield $msg` to wait on data to complete. The return value of that `yield` is a string with the full data.

`onClose($clientId, $code, $reason)` is called when any direction (ingoing or outgoing) of the websocket connection gets closed.

{:.note}
> Possibly it is not intuitive to have `onData()` called before the full message has been received, but it allows for incremental processing where needed, like large uploads over websockets. See the [usage and performance considerations about this](../performance/body.html).

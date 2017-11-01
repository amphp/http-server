---
title: WebSocket
permalink: /classes/websocket
---

* Table of Contents
{:toc}

The `Websocket` interface is the general interface for your websocket class. To set it as a responder, just pass an instance of it to the `websocket()` function whose result must be passed to [`Host::use()`](host.md#usemiddleware--bootable--callablerequest-response--monitor--httpdriver) or a specific route (see [`Router::route`](router.md#routestring-method-string-uri-callablemiddlewarebootablemonitor-actions-self)).

{:.note}
> `websocket()` returns a responder callable, it falls under the same rules as every responder callable passed to `use()`: after the first callable started the response, the following ones will be ignored. Make attention to not e.g. `(new Host)->use($router)->use($websocket)` and be then surprised why you get an invalid response with code 200 (OK).

Example:

```php
$websocket = Aerys\websocket(new MyAwesomeWebsocket);
(new Aerys\Host)->use($websocket);
```

## `onStart(Websocket\Endpoint)`

This method is called when the [`Server`](server.md) is in `STARTING` mode. The sole argument is the [`Websocket\Endpoint`](websocket-endpoint.md) instace via which you do the whole communication to the outside.

## `onHandshake(Request, Response)`

This is the chance to deny the handshake. The [`Request`](request.md) and [`Response`](response.md) are just like for any normal HTTP request.

To prevent a successful handshake, set the response to a status not equal to 101 (Switching Protocols).

In order to map data (like identification information) to a client, you can return a value which will be passed to `onOpen()` as second parameter

## `onOpen(int $clientId, $handshakeData)`

In case of a successful handshake, this method gets called. `$clientId` is an opaque and unique integer valid through a whole websocket session you can use for identifying a specific client. `$handshakeData` will contain whatever was returned before in `onHandshake()`.

## `onData(int $clientId, Websocket\Message)`

This method gets called each time a new data frame sequence is received.

{:.note}
> The second parameter is not a string, but a [`Websocket\Message` extends `Amp\ByteStream\Message`](http://amphp.org/byte-stream/message), which implements Promise. The yielded Promise will return a string or fail with a ClientException if the client disconnected before transmitting the full data.

## `onClose(int $clientId, int $code, string $reason)`

This method is called after the client has (been) disconnected: you must not use any [`Websocket\Endpoint`](websocket-endpoint.md) API with the passed client id in this method.

## `onStop(): Generator|Promise|null`

When the [`Server`](server.md) enters `STOPPING` state, this method is called. It is guaranteed that no further calls to any method except `onClose()` will happen after this method was called.

This means, you may only send, but not receive from this moment on. The clients are only forcefully closed after this methods call and the eventual returned Promise resolved.

## Full example

```php
class MyAwesomeWebsocket implements Aerys\Websocket {
    private $endpoint;

    public function onStart(Aerys\Websocket\Endpoint $endpoint) {
        $this->endpoint = $endpoint;
    }

    public function onHandshake(Aerys\Request $request, Aerys\Response $response) {
        // Do eventual session verification and manipulate Response if needed to abort
    }

    public function onOpen(int $clientId, $handshakeData) {
        $this->endpoint->send("Heyho!", $clientId);
    }

    public function onData(int $clientId, Aerys\Websocket\Message $msg) {
        // send back what we get in
        $msg = yield $msg; // Do not forget to yield here to get a string
        yield $this->endpoint->send($msg, $clientId);
    }

    public function onClose(int $clientId, int $code, string $reason) {
        // client disconnected, we may not send anything to him anymore
    }

    public function onStop() {
        $this->endpoint->broadcast("Byebye!");
    }
}

$websocket = Aerys\websocket(new MyAwesomeWebsocket);
$router = (new Aerys\Router)
    ->route('GET', '/websocket', $websocket)
    ->route('GET', '/', function(Aerys\Request $req, Aerys\Response $res) { $res->send('
<script type="text/javascript">
    ws = new WebSocket("ws://localhost:1337/ws");
    ws.onopen = function() {
        ws.send("ping");
    };
    ws.onmessage = function(e) {
        console.log(e.data);
    };
</script>'); });
return (new Aerys\Host)->use($router);
```

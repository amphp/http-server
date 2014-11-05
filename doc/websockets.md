Websockets
==========

> **[Table of Contents](#table-of-contents)**

## The Websocket API

Aerys exposes a simple but powerful websocket API:

```
interface Websocket {
	public function onOpen(int $clientId, array $httpRequestEnv);
	public function onData(int $clientId, string $data);
	public function onClose(int $clientId, int $code, string $reason);
}
```

These three methods expose the full range of websocket possibilities to your aerys application. A brief description of each method follows .

### onOpen()

This method is invoked when a valid client upgrade request is made to the websocket endpoint. At the time this method called the handshake response has *not yet* been dispatched to the client. At this time websocket applications have the ability to fail the handshake, send data to any/all connected clients and otherwise do anything they like.

Like all websocket methods the first parameter is a unique integer `$clientId` identifying the client connection responsible for the event. The second parameter `$httpRequestEnv` is the HTTP request array provided to all aerys HTTP endpoints. Applications may optionally use the request environment to load cookie/session data or otherwise validate that the client has permission to access the websocket resource.

### onData()

This method is invoked any time a websocket message arrives from a client socket. Like all websocket methods the first parameter is a unique integer `$clientId` identifying the client connection responsible for the event. The second parameter is the buffered string `$payload` received from the client.

### onClose()

This method is invoked when a client connection has closed. A close can result from any one of several instigating factors:

- The client closed the connection as specified in the websocket protocol
- The TCP connection to the client was unexpectedly severed
- The client failed to respond to heartbeat PINGs in a timely manner
- The client sent malformed data
- The client violated an internal policy such as allowable frame/message size constraints
- The websocket endpoint initiated the close handshake and it has now completed successfully
- The server is shutting down

Like all websocket methods the first parameter is a unique integer `$clientId` identifying the client connection responsible for the event.  The second parameter is an integer close `$code` describing the circumstances surrounding the close. The third parameter is a (potentially empty) string `$reason` specifying additional information describing the close.

> **NOTE**
> 
> Remember that `onClose()` is invoked *after* the client in question has already disconnected. If your application attempts to send data to the closed client an `Aerys\ClientGone` exception will be thrown into your `onClose()` generator.


## Yield For Great Justice

### Generators and You

Aerys websocket endpoints use the `Generator` functionality introduced in PHP 5.5 not only to minimize the cognitive load required for concurrent programming, but also as a means for message passing between your application and the server. `yield` keys act as "commands" to the websocket endpoint telling the server what your application wishes to do at any given stage. The best way to demonstrate this functionality is to look at some example code ...

#### Sending Data

This example demonstrates using the `"send"` and `"broadcast"` yield commands to push data out to connected websocket clients. There are a couple of important takeaways from this example:

- Any time your websocket methods yield a `"send"` command the associated string value will be relayed to the specific `$clientId` that instigated the current event.
- If you yield a `"send"` command inside your `onClose()` method an `Aerys\ClientGoneException` will be thrown back into your generator; it should be obvious why.
- Yielding the `"broadcast"` command will send the associated string to all clients currently connected to this specific endpoint.

```php
class ExampleWebsocket implements Aerys\Websocket {
    private $clientCount = 0;
    
    public function onOpen($clientId, array $httpEnvironment) {
        // Broadcast $msg to all connected clients
        $msg = "user count: " . ++$this->clientCount;
        yield 'broadcast' => $msg;
    }

    public function onData($clientId, $payload) {
		// Send $msg only to the $clientId client
		$msg = "echo chamber: {$payload}";
		yield 'send' => $msg;
    }

    public function onClose($clientId, $code, $reason) {
        // Broadcast $msg to all connected clients
        $msg = "user count: " . ++$this->clientCount;
        yield 'broadcast' => $msg;
    }
}
```

#### Advanced Broadcasts

In previous examples we've demonstrated a simple `"broadcast"` command to send data to all connected clients. However, often we wish to broadcast messages only to specific clients or groups of clients. In these cases we use the array argument form when yielding the `"broadcast"` command:

```php
class ExampleWebsocket implements Aerys\Websocket {
    private $evenClients = [];
    private $oddClients = [];
    
    public function onOpen($clientId, array $httpEnvironment) {
		if (time() % 2 === 0) {
			$this->evenClients[$clientId] = $clientId;
		} else {
			$this->oddClients[$clientId] = $clientId;
		}
		
		yield 'send' => "Hello, {$clientId}";
    }

    public function onData($clientId, $payload) {
		// An array of client IDs that should receive the message.
		// An empty $include array indicates "all clients connected
		// to this endpoint"
		$include = (time() % 2 === 0)
			? $this->evenClients
			: $this->oddClients;
		
		// An array specifying which clients to exclude. An empty
		// array means "don't exclude any clients from the list of
		// client IDs in the $include array"
		$exclude = [];
		
		// Echo this message only to appropriate even/odd clients
        yield 'broadcast' => ["new message!", $include, $exclude];
    }

    public function onClose($clientId, $code, $reason) {
        // clean up after ourselves when a client goes away
        unset(
			$this->evenClients[$clientId],
			$this->oddClients[$clientId]
		);
    }
}
```

#### Close Commands

Most applications do not need to manually close connections as the server handles the common situations automatically. However, for those that do, the `"close"` command works similarly to the `"broadcast"` command.

```php
class MyWebsocket implements Aerys\Websocket {
	public function onOpen($clientId, $httpRequestEnv) {
		yield 'send' => 'hi and goodbye!';
		echo "onOpen() - before close command\n";
		yield 'close' => $clientId;
		echo "onOpen() - after close command\n";
	}
	public function onData($clientId, $payload) {
		// do nothing, we don't care right now
	}
	public function onClose($clientId, $code, $reason) {
		echo "onClose()\n";
	}
}
```

If you were to run the above websocket you would observe the following output in your console each time a client connected:

```bash
onOpen() - before close command
onClose()
onOpen() - after close command
```

If you wish to specify your own custom close code and reason phrase you may do so by yielding the `"close"` command with an array as follows:

```
yield 'close' => [$clientId, $closeCode, $closeReason];
```

#### Nested Command Resolution

Of course, websocket applications aren't limited to the three public API methods. In non-trivial applications we can yield *nested* generators and the server will automatically resolve them and process any endpoint command keys along the way.

```php
function someAsyncCall() {
	return new Amp\Success(21);
}

class MyWebsocket implements Aerys\Websocket {
    public function onOpen($clientId, array $httpEnvironment) {
        yield $this->doSomethingAsynchronous();
    }

	private function doSomethingAsynchronous() {
		// Yield control until our async operation completes
		$result = (yield someAsyncCall()) * 2;
		assert($result === 42);
		
		// This send command is bound to the $clientId in
		// onOpen() where we originally invoked this function.
		yield 'send' => $result;
	}

    public function onData($clientId, $payload) {
        // ...
    }

    public function onClose($clientId, $code, $reason) {
        // ...
    }
}
```

The above example demonstrates how a nested generator's `"send"` command scope is bound to the `$clientId` from the original calling context.

> **NOTE**
> 
> Any event reactor timer or stream IO callbacks registered using commands such as `"once"`, `"repeat"`, etc also have their `"send"` commands bound to the `$clientId` associated with the calling context.

#### Error Handling

There is no need for a dedicated error handling mechanism in the `Aerys\Websocket` API because generator yields coalesce the process into a form with which programmers are already familiar: `try/catch` blocks.

The `yield` statements won't return control to an application's generator function until the associated operation completes. For `"send"` commands this means that control is returned in one of two states:

1. The message send completed successfully;
2. The client connection was severed before the message could be fully delivered.

In the event of a failure an `Aerys\ClientGoneException` is thrown into your generator. This means that applications wishing to verify message send completion should catch these errors. For example:

```php
class MyWebsocket implements Aerys\Websocket {
	function onOpen($clientId, $httpRequestEnv) {
		try {
			yield 'send' => 'Thus spake Zarathustra';
		} catch (Aerys\ClientGoneException $e) {
			// oh noes! the send didn't finish :(
		}
	}
	
	...
}
```

> **TIP**
> 
> There is *NO* need for Websocket applications to wrap all of their code inside endless try/catch blocks to prevent `ClientGoneException` instances from bubbling up the stack. The server automatically handles these cases for you. There is no harm to an application if a client disconnects mid-generator execution and the associated exception goes uncaught. The exception simply provides a hook for applications who requiring verification of whether or not a specific message was received by the client.

#### NOWAIT Prefix

When an application yields commands the server does not return control to the generator until the command resolves successfully (or fails resulting in an exception object thrown into the generator). This may not always be the preferred behavior, though. For example, an application may not care to wait until *all* recipients of a broadcast have received a message before proceeding.

In such cases applications may prefix their yield commands with the "NOWAIT" prefix, `@` to indicate that the success or failure of the operation is not important . Consider:

```php
class MyWebsocket implements Aerys\Websocket {
	function onOpen($clientId, $httpRequestEnv) {
		yield 'send' => 'You know nothing, Jon Snow.';
		// this line isn't reached until the send completes

		yield '@send' => 'I know some things.\n';
		// this line is reached immediately -- you won't
		// know when or if this send actually completes
	}
	
	...
}
```

> **NOTE**
>
> The `@` NOWAIT operator is similar to PHP's error suppression operator in that it will mask any errors that occur while performing the requested command. Its use also means that `ClientGoneException` instances *will not* be thrown into application generators in the event a client disconnects prior to send completion.

## Handshakes

As previously mentioned, the websocket handshake has not yet been sent at `Websocket::onOpen()` invocation time. By sending the handshake just in time (JIT) aerys affords applications the opportunity to perform such tasks as subprotocol/extension negotiation, user authorization, etc.

### The JIT Handshake

There are three execution points at which aerys may send the HTTP websocket handshake to a new client:

1. When a `"send"` command is issued inside `onOpen()` or a nested generator executing inside the `onOpen()` context;
2. When a `"broadcast"` command is issued inside `onOpen()` or a nested generator executing inside the `onOpen()` context;
3. When the `onOpen()`  generator resolves and no `"send"` or `"broadcast"` yield commands were issued.

#### Assigning Handshake Values

There are three types of information an application may assign to the websocket handshake response inside `onOpen()`:

1. HTTP status code (must be >= 400);
2. HTTP reason phrase
3. HTTP headers (may be assigned whether the handshake succeeds or fails);

In the following example we fail the websocket handshake with a 401 response if we determine that the client is not authorized to connect to this endpoint. Otherwise aerys automatically sends the JIT succcess handshake when we yield the `"send"` command.

```php
function asyncAuthorizationMock() {
	$isAuthorized = (time() % 2 === 0);
	return new Success($isAuthorized);
}

class MyWebsocket implements Aerys\Websocket {
	function onOpen($clientId, $httpRequestEnv) {
		$isAuthorized = (yield asyncAuthorizationMock());
		if ($isAuthorized) {
			yield 'send' => 'Welcome!';
		} else {
			yield 'status' => 401;
			yield 'reason' => 'Authorization required';
		}
	}
	
	...
}
```

> **NOTE**
> 
> It's important to note that only HTTP status codes >= 400 are accepted when yielding `"status"` commands. The 101 status code is implied unless an application explicitly wishes to fail the handshake with a 4xx or 5xx error response. Also note that reason phrases assigned are discarded if the handshake is not explicitly failed. Any headers assigned via the `"header"` yield command are relayed to the client regardless of whether or not the handshake is a success.

#### Subprotocols, Extensions and Origins

The websocket specification allows for the negotiation of custom subprotocols and extensions in addition to verification based on an HTTP request's origin. Application endpoints have full access to the `$httpRequestEnv` array inside `onOpen()` and may negotiate these values by comparing their available capabilities to the HTTP headers present in the request environment. Here we demonstrate a simple example of denying connections from requests specifying an origin header that we don't wish to support:

```php
class MyWebsocket implements Aerys\Websocket {
	private $origin = 'mysite.com';
	
	function onOpen($clientId, $httpRequestEnv) {
		$origin = empty($httpRequestEnv['HTTP_ORIGIN'])
			? null
			: $httpRequestEnv['HTTP_ORIGIN'];

		if ($origin !== $this->origin) {
			yield 'status' => 400;
			yield 'reason' => 'Bad request: origin not allowed';
			yield 'header' => "Access-Control-Allow-Origin: {$this->origin}";
			return;
		}
		
		yield 'send' => 'hello!';
	}
	
	...
}
```

Negotiating extensions and subprotocols would follow a similar path as the above origin example. The relevant `$httpRequestEnv` keys are listed here:

Negotiable Value        | Request Environment Header Key
----------------------- | ----------------------
| Extension             | `HTTP_SEC_WEBSOCKET_EXTENSIONS` |
| Subprotocol           | `HTTP_SEC_WEBSOCKET_PROTOCOL` |
| Origin                | `HTTP_ORIGIN` |


## Configuring Endpoints

Like all routed aerys endpoints websockets are defined using `Aerys\Host` instances in the server configuration file. Hosts expose the following method to add websocket routes:

```
Host::addWebsocket(string $uri, mixed $websocketClassOrObj, array $options =[]);
```

The following simple aerys configuration exposes a websocket endpoint at `http://mysite.com/mywebsocket`. The behavior of the websocket endpoint is defined by the `MyWebsocketClass`. In this example we pass a string class name but note that applications may also manually instantiate their own instance of the `Aerys\Websocket` interface and pass the instance itself. In the event of a string the server will automatically provision a new instance of the specified class and use it as the endpoint controller.

```php
<?php // Server config with a websocket endpoint
$mySite = new Aerys\Host;
$mySite->setName('mysite.com');
$mySite->addWebsocket('/mywebsocket', 'MyWebsocketClass');
```

As websocket endpoints aren't terribly useful on their own without accompanying HTML and Javascript files we'll generally also add static file serving capability to our configuration:

```php
<?php // Server config with websocket endpoint + static files
$mySite = new Aerys\Host;
$mySite->setName('mysite.com');
$mySite->addWebsocket('/mywebsocket', 'MyWebsocketClass');
$mySite->addRoot('/hard/path/to/docroot');
```

Using the above configuration aerys routes any requests to `/mywebsocket` to our websocket endpoint handler. All other requests will be handled as requests for static files and served from the specified document root.


### Assigning Endpoint Options

The `$options` parameter allows users to fine-tune the behavior of their websocket endpoints as shown here:

```php
<?php // Server config customizing websocket endpoint options
use Aerys\Host, Aerys\Websocket\Endpoint;

$mySite = new Host;
$mySite->setName('mysite.com');
$mySite->addWebsocket('/mywebsocket', 'MyWebsocketClass', $options = [
	Endpoint::OP_MAX_FRAME_SIZE => 4096,
	Endpoint::OP_MAX_MSG_SIZE => 32768,
]);
```

In the above example we've set the maximum allowable size (in bytes) of frames and messages that we will accept from clients.

### Available Options

The following websocket endpoint options are available for assignment in configuration files:

Endpoint Constant       | Description            | Default
----------------------- | ---------------------- | --------------
| OP_MAX_FRAME_SIZE     | The maximum allowed size (in bytes) of any one inbound frame | 2097152 |
| OP_MAX_MSG_SIZE       | The maximum allowed aggregate size (in bytes) of any one inbound message | 10485760 |
| OP_HEARTBEAT_PERIOD   | The number of seconds of inactivity on a connected client session before a heartbeat PING is sent; if <= 0 no heartbeats are sent | 10 |
| OP_CLOSE_PERIOD       | The number of seconds to wait for clients to respond to a CLOSE frame before the connection is severed by the server | 3 |
| OP_VALIDATE_UTF8      | Should the server validate incoming TEXT frames to be sure they only contain UTF-8 data? | false |
| OP_TEXT_ONLY          | Does the server only ever deal in TEXT frames (optimization to avoid determining which opcode the server should send with each frame) | false |
| OP_AUTO_FRAME_SIZE    | The size in bytes at which the server will automatically fragment an outbound message into multiple frames | 32768 |
| OP_QUEUED_PING_LIMIT  | How many consecutive unanswered PING frames the server will tolerate before severing a connection (prevents memory overflow DoS because servers must retain PING payloads to compare against PONG responses) | 3 |


## Appendix


### Yield Command Reference


#### Websocket Commands

Command       | Description
------------- | ----------------------
| send        | Send the yielded string to the client responsible for the current event |
| broadcast   | Broadcast the associated message to all clients or a filtered set of clients |
| close       | Close the client specified in the yield value |
| inspect     | Retrieve an array of stats regarding the yielded client ID |


#### Handshake Commands

Command       | Description
------------- | ----------------------
| status      | Fail a websocket handshake with the yielded HTTP status code |
| reason      | Specify an HTTP reason phrase to send when failing the websocket handshake |
| header      | Assign a string (or an array of strings) to send with the handshake response |


#### Event Reactor Commands

Command       | Description
------------- | ----------------------
| wait        | Pause generator execution for the yielded number of milliseconds |
| immediately | Resolve the yielded callback on the next iteration of the event loop |
| once        | Resolve the yielded callback at array index 0 in array index 1 milliseconds |
| repeat      | Repeatedly resolve the yielded callback at array index 0 every array index 1 milliseconds |
| onreadable  | Resolve the yielded callback at array index 1 when the stream resource at index 0 reports as readable |
| onwritable  | Resolve the yielded callback at array index 1 when the stream resource at index 0 reports as writable |
| enable      | Enable the yielded event watcher ID |
| disable     | Disable the yielded event watcher ID |
| cancel      | Cancel the yielded event watcher ID |


#### Promise Combinator Commands

Command       | Description
------------- | ----------------------
| all         | Flatten the array of promises/generators and return control when all individual elements resolve successfully; fail the result if any individual resolution fails |
| any         | Flatten the array of promises/generators and return control when all individual elements resolve; never fail the result regardless of component failures |
| some        | Flatten the array of promises/generators and return control when all individual elements resolve; only fail the result if all components fail |

#### Other Commands

Command       | Description
------------- | ----------------------
| nowait      | Don't wait on the yielded promise or generator to resolve before returning control to the generator |
| @ (prefix)  | Prefixed to another command to indicate the result should not be waited on before returning control to the generator |


----------------------------------------------

## Table of Contents

[TOC]
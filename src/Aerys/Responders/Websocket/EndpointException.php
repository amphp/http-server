<?php

namespace Aerys\Responders\Websocket;

/**
 * Thrown internally by the WebsocketResponder if a userland endpoint throws.
 */
class EndpointException extends \RuntimeException {}

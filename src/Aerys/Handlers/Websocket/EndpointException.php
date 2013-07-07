<?php

/**
 * Thrown internally by the WebsocketHandler if a userland endpoint throws
 * when in control of the program.
 */
class EndpointException extends \RuntimeException {}

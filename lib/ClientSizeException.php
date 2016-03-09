<?php

namespace Aerys;

/**
 * ~~~~~~~~~~~~~~~ WARNING ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
 * This class is strictly for internal Aerys use!
 * Do NOT throw it in userspace code or you risk breaking things.
 * ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
 *
 * ClientSizeException indicates that the requesting client has exceeded size
 * limits and the connection is going to be closed (except if caught and size
 * increased).
 * It probably is still connected and able to receive data on the outgoing stream.
 *
 * As this is a ClientException:
 * Applications may optionally catch this exception in their callable actions
 * to continue other processing. Users are NOT required to catch it and if left
 * uncaught it will simply end responder execution.
 * 
 * Additionally, one can catch it and try to continue with bigger size limits.
 * 
 * If this exception is thrown back into the server handler, a 413 - Request
 * entity too large response will be generated if response hasn't started yet.
 */

class ClientSizeException extends ClientException {}
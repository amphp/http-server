<?php

namespace Aerys;

/**
 * ~~~~~~~~~~~~~~~ WARNING ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
 * This class is strictly for internal Aerys use!
 * Do NOT throw it in userspace code or you risk breaking things.
 * ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
 *
 * ClientException indicates that the requesting client has (or will be) closed
 * the incoming data stream. [if thrown on a reading action]
 * It may still be connected and receive data on the outgoing stream.
 * [If thrown during a write action, the client is immediately and completely
 * disconnected.]
 *
 * Applications may optionally catch this exception in their callable actions
 * to continue other processing. Users are NOT required to catch it and if left
 * uncaught it will simply end responder execution.
 */
class ClientException extends \Exception {}

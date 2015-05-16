<?php

namespace Aerys;

/**
 * ~~~~~~~~~~~~~~~ WARNING ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
 * This class is strictly for internal Aerys use!
 * Do NOT throw it in userspace code or you risk breaking things.
 * ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
 *
 * ClientException indicates that the requesting client has disconnected
 *
 * Applications may optionally catch this exception in their callable actions
 * to continue other processing. Users are NOT required to catch it and if left
 * uncaught it will simply end responder execution.
 */
final class ClientException extends \Exception {}

<?php

namespace Aerys\Parsing;

/**
 * Exception thrown to indicate a policy failure. This does not indicate a malformed HTTP message.
 * Instead a PolicyException means that the streaming request exceeded one of the constraints of the
 * parser; for example, a message exceeding the allowed header or entity body size.
 */
class PolicyException extends ParseException {}

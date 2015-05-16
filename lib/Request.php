<?php

namespace Aerys;

/**
 * At this time Requests are strictly object "structs" without a method API.
 * This interface exists (and is implemented by Rfc7230Request) so that
 * applications using the RFC7230-compliant HTTP/1.1 server won't have to
 * modify any code once HTTP/2.0 support is added.
 */
interface Request {}

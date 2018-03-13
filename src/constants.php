<?php

namespace Amp\Http\Server;

\define(
    __NAMESPACE__ . "\\DEFAULT_ERROR_HTML",
    \file_get_contents(\dirname(__DIR__) . "/resources/error.html")
);

\define(
    __NAMESPACE__ . "\\INTERNAL_SERVER_ERROR_HTML",
    \file_get_contents(\dirname(__DIR__) . "/resources/internal-server-error.html")
);

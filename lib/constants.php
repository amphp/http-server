<?php

namespace Aerys;

\define(
    __NAMESPACE__ . "\\DEFAULT_ERROR_HTML",
    \file_get_contents(\dirname(__DIR__) . "/etc/error.html")
);

\define(
    __NAMESPACE__ . "\\INTERNAL_SERVER_ERROR_HTML",
    \file_get_contents(\dirname(__DIR__) . "/etc/internal-server-error.html")
);

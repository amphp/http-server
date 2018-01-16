<?php

namespace Aerys;

use PackageVersions\Versions;

const SERVER_NAME = "aerys";

\define(
    __NAMESPACE__ . "\\SERVER_VERSION",
    \str_replace([".9999999", "9999999-"], "", Versions::getVersion('amphp/aerys'))
);

const SERVER_TOKEN = SERVER_NAME . "/" . SERVER_VERSION;

\define(
    __NAMESPACE__ . "\\DEFAULT_ERROR_HTML",
    \file_get_contents(\dirname(__DIR__) . "/etc/error.html")
);

\define(
    __NAMESPACE__ . "\\INTERNAL_SERVER_ERROR_HTML",
    \file_get_contents(\dirname(__DIR__) . "/etc/internal-server-error.html")
);

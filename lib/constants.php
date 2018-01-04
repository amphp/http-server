<?php

namespace Aerys;

use PackageVersions\Versions;

const SERVER_NAME = "aerys";

\define(__NAMESPACE__ . "\\SERVER_VERSION", \str_replace([".9999999", "9999999-"], "", Versions::getVersion('amphp/aerys')));

const SERVER_TOKEN = SERVER_NAME . "/" . SERVER_VERSION;

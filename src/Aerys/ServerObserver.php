<?php

namespace Aerys;

interface ServerObserver {
    function onServerUpdate(Server $server, $status);
}
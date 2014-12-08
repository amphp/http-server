<?php

namespace Aerys;

interface ServerObserver {
    public function onServerUpdate(Server $server);
}

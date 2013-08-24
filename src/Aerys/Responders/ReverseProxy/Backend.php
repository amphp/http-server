<?php

namespace Aerys\Responders\ReverseProxy;

class Backend {
    public $uri;
    public $connections = [];
    public $availableConnections = [];
    public $consecutiveConnectFailures = 0;
    public $cachedConnectionCount = 0;
}

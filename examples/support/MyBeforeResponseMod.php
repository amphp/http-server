<?php

use Aerys\Server,
    Aerys\Mods\BeforeResponseMod;

class MyBeforeResponseMod implements BeforeResponseMod {

    private $server;

    function __construct(Server $server) {
        $this->server = $server;
    }

    function beforeResponse($requestId) {
        $asgiResponse = $this->server->getResponse($requestId);

        if ($asgiResponse[0] == 200) {
            $newBody = '<html><body><h1>Zanzibar!</h1><p>(Assigned by MyCustomMod)</p></body></html>';
            $asgiResponse[3] = $newBody;
            $this->server->setResponse($requestId, $asgiResponse);
        }
    }
}

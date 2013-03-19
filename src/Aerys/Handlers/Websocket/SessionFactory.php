<?php

namespace Aerys\Handlers\Websocket;

use Aerys\Handlers\Websocket\Io\StreamFactory,
    Aerys\Handlers\Websocket\Io\FrameParser,
    Aerys\Handlers\Websocket\Io\FrameWriter;

class SessionFactory {
    
    private $streamFactory;
    private $clientFactory;
    
    function __construct(StreamFactory $streamFactory = NULL, ClientFactory $clientFactory = NULL) {
        $this->streamFactory = $streamFactory ?: new StreamFactory;
        $this->clientFactory = $clientFactory ?: new ClientFactory;
    }
    
    function make($socket, SessionManager $manager, Endpoint $endpoint, array $asgiEnv) {
        $parser = new FrameParser($socket);
        $writer = new FrameWriter($socket);
        
        return new Session(
            $manager,
            $parser,
            $writer,
            $endpoint,
            $asgiEnv,
            $this->streamFactory,
            $this->clientFactory
        );
    }
    
}


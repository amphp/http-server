<?php

namespace Aerys\Handlers\Websocket;

class SessionFactory {
    
    private $streamFactory;
    private $clientFactory;
    
    function __construct(FrameStreamFactory $streamFactory = NULL, ClientFactory $clientFactory = NULL) {
        $this->streamFactory = $streamFactory ?: new FrameStreamFactory;
        $this->clientFactory = $clientFactory ?: new ClientFactory;
    }
    
    function make($socket, SessionManager $manager, Endpoint $endpoint, array $asgiEnv) {
        $parser = new FrameParser;
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


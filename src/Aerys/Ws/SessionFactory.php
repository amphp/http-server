<?php

namespace Aerys\Ws;

use Aerys\Ws\Io\StreamFactory,
    Aerys\Ws\Io\FrameParser,
    Aerys\Ws\Io\FrameWriter;

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


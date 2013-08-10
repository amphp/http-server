<?php

namespace Aerys\Mods\Protocol;

interface ProtocolHandler {
    
    /**
     * Determine if the initial protocol negotiation is a success
     * 
     * This method is invoked just before Aerys sends a 400 Bad Request response. As far as the
     * HTTP server is concerned the message was invalid because it didn't match the expected HTTP
     * request format. The contents of the "rejected message" are passed here so we can judge
     * whether its contents match the expected format for our socket protocol. This method
     * should return TRUE if the socket should indeed be exported for handling by this class or
     * FALSE if Aerys should continue on with sending the 400 response to the HTTP client.
     * 
     * In the event of a truthy response Aerys will export the socket to ModProtocol and our onOpen()
     * method will be invoked with an identifying reference to the exported socket.
     * 
     * @param string $rejectedHttpMessage
     * @param array $socketInfo Informational data about the socket connection responsible for the request
     */
    function negotiate($rejectedHttpMessage, array $socketInfo);
    
    /**
     * Invoked with the raw socket on successful protocol initiation
     * 
     * @param int $socketId A unique socket identifier
     * @param string $openingMessage The raw data used to negotiate the protocol connection
     * @param array $socketInfo Informational data about the connected socket
     */
    function onOpen($socketId, $openingMessage, array $socketInfo);
    
    /**
     * Invoked when new data is read from the socket
     * 
     * @param string $socketId
     * @param string $data 
     */
    function onData($socketId, $data);
    
    /**
     * Invoked after a socket connection has been closed
     * 
     * A connection is autmatically closed if the socket connection is severed. This method will
     * also be invoked after server-initiated closes caused by the handler manually calling
     * ModProtocol::close().
     * 
     * @param string $socketId
     * @param int $closeReason
     */
    function onClose($socketId, $closeReason);
    
}

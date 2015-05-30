<?php

namespace Aerys;

interface Response {
    const NONE      = 0b000;
    const STARTED   = 0b001;
    const STREAMING = 0b010;
    const ENDED     = 0b100;

    /**
     * Set the numeric HTTP status code
     *
     * If not assigned this value defaults to 200.
     *
     * @param int $code An integer in the range [100-599]
     * @return self
     */
    public function setStatus(int $code): Response;

    /**
     * Set the optional HTTP reason phrase
     *
     * @param string $phrase A human readable string describing the status code
     * @return self
     */
    public function setReason(string $phrase): Response;

    /**
     * Append the specified header
     *
     * @param string $field
     * @param string $value
     * @return self
     */
    public function addHeader(string $field, string $value): Response;

    /**
     * Set the specified header
     *
     * This method will replace any existing headers for the specified field.
     *
     * @param string $field
     * @param string $value
     * @return self
     */
    public function setHeader(string $field, string $value): Response;

    /**
     *
     *
     * @param string $name
     * @param string $value
     * @param array $flags
     * @return self
     */
    public function setCookie(string $name, string $value, array $flags = []): Response;

    /**
     * Send the specified response entity body
     *
     * This method effectively ends the response and will always outperform
     * the Response::stream() approach if the response body isn't so large
     * that buffering it all at once is problematic.
     *
     * Note: Headers are sent when Response::send() is called.
     *
     * @param string $body The full response entity body
     * @return self
     */
    public function send(string $body): Response;

    /**
     * Incrementally stream parts of the response entity body
     *
     * This method may be repeatedly called to stream the response body.
     * Applications that can afford to buffer an entire response in memory or
     * can wait for all body data to generate may use Response::send() to output
     * the entire response in a single call.
     *
     * Note: Headers are sent upon the first invocation of Response::stream().
     *
     * @param string $partialBodyChunk A portion of the response entity body
     * @return self
     */
    public function stream(string $partialBodyChunk): Response;

    /**
     * Request that buffered stream data be flushed to the client
     *
     * This method only makes sense when streaming output via Response::stream().
     * Invoking it before calling stream() or after send()/end() is a logic error.
     *
     * @return self
     */
    public function flush(): Response;

    /**
     * Signify the end of streaming response output
     *
     * User applications are NOT required to call Response::end() after streaming
     * or sending response data (though it's not incorrect to do so) -- the server
     * will automatically call end() as needed.
     *
     * Passing the optional $finalBodyChunk parameter is a shortcut equivalent to
     * the following:
     *
     *     $response->stream($finalBodyChunk);
     *     $response->end();
     *
     * Note: Invoking Response::end() with a non-empty $finalBodyChunk parameter
     * without having previously invoked Response::stream() is equivalent to calling
     * Response::send($finalBodyChunk).
     *
     * @param string $finalBodyChunk Optional final body data to send
     * @return self
     */
    public function end(string $finalBodyChunk = null): Response;

    /**
     * Specify a callback to receive the raw socket after completing an HTTP upgrade response
     *
     * If an onUpgrade callback is specified it will be invoked with the raw client socket
     * stream after a 101 Switching Protocols response. At this point the socket is no longer
     * monitored by the server -- it is up to the exporting application to service future
     * reads and writes. If a Connection: close header is sent the upgrade callback will not
     * be invoked for obvious reasons.
     *
     * Callbacks should expose the following signature:
     *
     * function(resource $socketStream, \Closure $clearServerReference): void;
     *
     * When a socket is exported it continues to count against the server's "maxConnections"
     * limit. Exporting applications must invoke the $clearServerReference callback when
     * finished with the socket to prevent a scenario where all connections slots are eventually
     * occupied and no new clients can connect.
     *
     * @param callable $onUpgrade
     * @return self
     */
    public function onUpgrade(callable $onUpgrade): Response;

    /**
     * Retrieve the current response state
     *
     * The response state is a bitmask of the following flags:
     *
     *  - Response::NONE
     *  - Response::STARTED
     *  - Response::STREAMING
     *  - Response::ENDED
     *
     * @return int
     */
    public function state(): int;
}

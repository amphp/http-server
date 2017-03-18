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
     * Provides an easy API to set cookie headers
     * Those who prefer using addHeader() may do so.
     *
     * @param string $name
     * @param string $value
     * @param array $flags Shall be an array of key => value pairs and/or unkeyed values as per https://tools.ietf.org/html/rfc6265#section-5.2.1
     * @return self
     */
    public function setCookie(string $name, string $value, array $flags = []): Response;

    /**
     * Incrementally stream parts of the response entity body
     *
     * This method may be repeatedly called to stream the response body.
     * Applications that can afford to buffer an entire response in memory or
     * can wait for all body data to generate may use Response::end() to output
     * the entire response in a single call.
     *
     * Note: Headers are sent upon the first invocation of Response::stream().
     *
     * @param string $partialBodyChunk A portion of the response entity body
     * @return \Amp\Promise to be succeeded whenever local buffers aren't full
     */
    public function stream(string $partialBodyChunk): \Amp\Promise;

    /**
     * Request that buffered stream data be flushed to the client
     *
     * This method only makes sense when streaming output via Response::stream().
     * Invoking it before calling stream() or after end() is a logic error.
     */
    public function flush();

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
     */
    public function end(string $finalBodyChunk = null);

    /**
     * Indicate resources which a client likely needs to fetch. (e.g. Link: preload or HTTP/2 Server Push)
     *
     * @param string $url The URL this request should be dispatched to
     * @param array $headers Optional custom headers, else the server will try to reuse headers from the last request
     * @return Response
     */
    public function push(string $url, array $headers = null): Response;

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

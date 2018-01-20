<?php

namespace Aerys;

interface HttpDriver {
    /**
     * HTTP methods that are *known*. Requests for methods not defined here or within Options should result in a 501
     * (not implemented) response.
     */
    const KNOWN_METHODS = ["GET", "HEAD", "POST", "PUT", "PATCH", "DELETE", "OPTIONS", "TRACE"];

    /**
     * Define the callables to be invoked when messages are parsed, an error occurs, or data should is to be written.
     *
     * @param \Aerys\Client $client
     * @param callable $onMessage
     * @param callable $onError
     * @param callable $write
     * @param callable $pause
     */
    public function setup(Client $client, callable $onMessage, callable $onError, callable $write, callable $pause);

    /**
     * Returns a generator used to write the response body.
     *
     * @param \Aerys\Response $response
     * @param \Aerys\Request|null $request
     *
     * @return \Generator
     */
    public function writer(Response $response, Request $request = null): \Generator;

    /**
     * Note that you *can* rely on keep-alive timeout terminating the Body with a ClientException, when no further
     * data comes in. No need to manually handle that here.
     *
     * @return \Generator
     */
    public function parser(): \Generator;

    /**
     * @return int Number of requests that are being read.
     */
    public function pendingRequestCount(): int;

    /**
     * @return int Number of requests emitted that are awaiting a response.
     */
    public function pendingResponseCount(): int;
}

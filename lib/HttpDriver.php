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
     * @param callable $write
     */
    public function setup(Client $client, callable $onMessage, callable $write);

    /**
     * Returns a generator used to write the response body. Data to be written is sent to the generator.
     *
     * @param \Aerys\Response $response
     * @param \Aerys\Request|null $request
     *
     * @return \Generator
     */
    public function writer(Response $response, Request $request = null): \Generator;

    /**
     * Data read from the client connection is sent to the generator returned from this method. If the generator
     * yields a promise, no additional data is sent to the parser until the promise resolves. Each yield must be
     * prepared to receive additional client data, including those yielding promises.
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

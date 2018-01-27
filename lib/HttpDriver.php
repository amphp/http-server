<?php

namespace Aerys;

interface HttpDriver {
    /**
     * HTTP methods that are *known*. Requests for methods not defined here or within Options should result in a 501
     * (not implemented) response.
     */
    const KNOWN_METHODS = ["GET", "HEAD", "POST", "PUT", "PATCH", "DELETE", "OPTIONS", "TRACE", "CONNECT"];

    /**
     * Data read from the client connection should be sent to the generator returned from this method. If the generator
     * yields a promise, no additional data is to be sent to the parser until the promise resolves. Each yield must be
     * prepared to receive additional client data, including those yielding promises.
     *
     * @param \Aerys\Client $client The client associated with the data being sent to the returned generator.
     * @param callable $onMessage Invoked with an instance of Request when the returned parser has parsed a request.
     * @param callable $write Invoked with raw data to be written to the client connection.
     *
     * @return \Generator Request parser.
     */
    public function setup(Client $client, callable $onMessage, callable $write): \Generator;

    /**
     * Returns a generator used to write the response body. Data to be written is sent to the generator. The generator
     * may return at any time to indicate that body data is no longer desired.
     *
     * @param \Aerys\Response $response
     * @param \Aerys\Request|null $request
     *
     * @return \Generator
     */
    public function writer(Response $response, Request $request = null): \Generator;

    /**
     * @return int Number of requests that are being read by the parser.
     */
    public function pendingRequestCount(): int;
}

<?php

namespace Amp\Http\Server\Driver;

use Amp\Future;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;

interface HttpDriver
{
    /**
     * HTTP methods that are *known*. Requests for methods not defined here or within Options should result in a 501
     * (not implemented) response.
     */
    public const KNOWN_METHODS = ["GET", "HEAD", "POST", "PUT", "PATCH", "DELETE", "OPTIONS", "TRACE", "CONNECT"];

    /**
     * Setup the driver.
     *
     * Data read from the client connection should be sent to the generator returned from this method. If the generator
     * yields a promise, no additional data is to be sent to the parser or read from the client until the promise
     * resolves. Yielding null indicates the parser needs more data. NULL will be sent to the generator upon promise
     * resolution. The generator MUST yield only null or a promise.
     *
     * @param Client $client The client associated with the data being sent to the returned generator.
     * @param Closure(Request):void $onMessage Invoked with an instance of Request when the returned parser has parsed
     * a request. Returns a {@see Future} that is resolved once the response has been generated and writing the response
     * to the client initiated (but not necessarily complete).
     * @param Closure(string):Future $write Invoked with raw data to be written to the client connection. Returns a
     * {@see Future} that is resolved when the data has been successfully written.
     *
     * @return \Generator Request parser.
     */
    public function setup(Client $client, \Closure $onMessage, \Closure $write): \Generator;

    /**
     * Write the given response to the client using the write callback provided to `setup()`.
     *
     * @param Request $request
     * @param Response $response
     */
    public function write(Request $request, Response $response): void;

    /**
     * @return int Number of requests that are being read by the parser.
     */
    public function getPendingRequestCount(): int;

    /**
     * Stops processing further requests, returning once all currently pending requests have been fulfilled and any
     * remaining data is send to the client (such as GOAWAY frames for HTTP/2).
     */
    public function stop(): void;
}

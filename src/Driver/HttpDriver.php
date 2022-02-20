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
     * Data read from the client connection should be sent to the generator returned from this method.
     *
     * @param Client $client The client associated with the data being sent to the returned generator.
     * @param Closure(Request, string):Future $onMessage Invoked with an instance of Request when the returned
     * parser has parsed a request. Returns a {@see Future} that is resolved once the response has been generated and
     * writing the response to the client initiated (but not necessarily complete).
     * @param Closure(string, bool):void $write Invoked with raw data to be written to the client connection. Returns
     * when the data has been successfully written. If the second param is true, the client is closed after the data
     * is written.
     *
     * @return \Generator Request parser.
     */
    public function setup(Client $client, \Closure $onMessage, \Closure $write): \Generator;

    /**
     * Write the given response to the client using the write callback provided to `setup()`.
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

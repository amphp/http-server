<?php

namespace Amp\Http\Server;

use Amp\ByteStream\InputStream;
use Amp\Http\Status;
use Amp\Promise;
use Amp\Socket\Server as Socket;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use function Amp\call;

function redirectTo(string $uri, int $statusCode = Status::FOUND): Response
{
    return new Response($statusCode, ['location' => $uri]);
}

/**
 * Returns a response with the html content type.
 *
 * @param InputStream|string|null $content
 */
function html($content, int $status = Status::OK): Response
{
    return new Response($status, [
        'Content-Type' => 'text/html; charset=utf-8'
    ], $content);
}

/**
 * Creates an HTTP server.
 *
 * This function is inspired in Golang's http module one. It provides sensible defaults for a really quick start
 * and it is designed to cover 90% of use cases.
 *
 * If you need fine-tuning, like listening in two sockets or attach more listeners to the server
 * you should bootstrap your server manually.
 *
 * @return Promise<HttpServer>
 */
function listenAndServe(string $address, RequestHandler $handler, ?Options $options, ?LoggerInterface $logger): Promise
{
    return call(static function () use ($address, $handler, $options, $logger) {
        $sockets = [
            Socket::listen($address)
        ];

        $logger = $logger ?? new NullLogger();

        $server = new HttpServer($sockets, $handler, $logger, $options);

        yield $server->start();

        return $server;
    });
}

/**
 * Wraps a php callable in an amp http server handler.
 *
 * @param callable(Request): Promise<Response> $callable
 */
function handleFunc(callable $callable): RequestHandler
{
    return new RequestHandler\CallableRequestHandler($callable);
}

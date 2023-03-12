<?php

namespace Amp\Http\Server\Middleware;

use Amp\Http\Server\ClientException;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Psr\Log\LoggerInterface as PsrLogger;
use Psr\Log\LogLevel;

class AccessLoggerMiddleware implements Middleware
{
    public function __construct(
        private readonly PsrLogger $logger,
    ) {
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $client = $request->getClient();

        $local = $client->getLocalAddress()->toString();
        $remote = $client->getRemoteAddress()->toString();
        $method = $request->getMethod();
        $uri = (string) $request->getUri();
        $protocolVersion = $request->getProtocolVersion();

        $context = [
            'method' => $method,
            'uri' => $uri,
            'protocolVersion' => $protocolVersion,
            'local' => $local,
            'remote' => $remote,
        ];

        $this->logger->info(\sprintf("%s %s HTTP/%s %s on %s", $method, $uri, $protocolVersion, $remote, $local), $context);

        try {
            $response = $requestHandler->handleRequest($request);
        } catch (ClientException $exception) {
            $this->logger->warning(\sprintf(
                "Client exception for %s %s HTTP/%s %s",
                $method,
                $uri,
                $protocolVersion,
                $remote,
            ), $context);

            throw $exception;
        }

        $status = $response->getStatus();
        $reason = $response->getReason();

        $context = [
            'request' => $context,
            'response' => [
                'status' => $status,
                'reason' => $reason,
            ]
        ];

        $level = $status < 400 ? LogLevel::INFO : LogLevel::NOTICE;

        $this->logger->log($level, \sprintf(
            "%d (%s) for %s %s HTTP/%s %s on %s",
            $status,
            $reason,
            $method,
            $uri,
            $protocolVersion,
            $remote,
            $local,
        ), $context);

        return $response;
    }
}

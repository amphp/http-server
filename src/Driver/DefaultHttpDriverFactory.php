<?php

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Amp\Http\Server\Server;
use Amp\Http\Server\ServerObserver;
use Amp\Promise;
use Amp\Success;
use Psr\Log\LoggerInterface as PsrLogger;

final class DefaultHttpDriverFactory implements HttpDriverFactory, ServerObserver
{
    /** @var Options */
    private $options;

    /** @var TimeReference */
    private $timeReference;

    /** @var ErrorHandler */
    private $errorHandler;

    /** @var PsrLogger */
    private $logger;

    public function onStart(Server $server): Promise
    {
        $this->options = $server->getOptions();
        $this->timeReference = $server->getTimeReference();
        $this->errorHandler = $server->getErrorHandler();
        $this->logger = $server->getLogger();
        return new Success;
    }

    public function onStop(Server $server): Promise
    {
        return new Success;
    }

    /** {@inheritdoc} */
    public function selectDriver(Client $client): HttpDriver
    {
        if ($client->isEncrypted() && $client->getTlsInfo()->getApplicationLayerProtocol() === "h2") {
            return new Http2Driver($this->options, $this->timeReference, $this->logger);
        }

        return new Http1Driver($this->options, $this->timeReference, $this->errorHandler, $this->logger);
    }

    /** {@inheritdoc} */
    public function getApplicationLayerProtocols(): array
    {
        return ["h2", "http/1.1"];
    }
}

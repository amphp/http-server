<?php

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\Server;
use Amp\Http\Server\ServerObserver;
use Amp\Promise;
use Amp\Success;

final class DefaultHttpDriverFactory implements HttpDriverFactory, ServerObserver {
    /** @var \Amp\Http\Server\Options */
    private $options;

    /** @var \Amp\Http\Server\Driver\TimeReference */
    private $timeReference;

    /** @var \Amp\Http\Server\ErrorHandler */
    private $errorHandler;

    public function onStart(Server $server): Promise {
        $this->options = $server->getOptions();
        $this->timeReference = $server->getTimeReference();
        $this->errorHandler = $server->getErrorHandler();
        return new Success;
    }

    public function onStop(Server $server): Promise {
        return new Success;
    }

    /** {@inheritdoc} */
    public function selectDriver(Client $client): HttpDriver {
        if ($client->isEncrypted() && ($client->getCryptoContext()["alpn_protocol"] ?? null) === "h2") {
            return new Http2Driver($this->options, $this->timeReference);
        }

        return new Http1Driver($this->options, $this->timeReference, $this->errorHandler);
    }

    /** {@inheritdoc} */
    public function getApplicationLayerProtocols(): array {
        return ["h2", "http1.1"];
    }
}

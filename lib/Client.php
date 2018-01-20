<?php

namespace Aerys;

use Amp\CallableMaker;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;
use Psr\Log\LoggerInterface as PsrLogger;

class Client {
    use CallableMaker;

    const CLOSED_RD = 1;
    const CLOSED_WR = 2;
    const CLOSED_RDWR = 3;

    /** @var int */
    private $id;

    /** @var resource Stream socket resource */
    private $socket;

    /** @var string */
    private $clientAddress;

    /** @var int */
    private $clientPort;

    /** @var string */
    private $clientNet;

    /** @var string */
    private $serverAddress;

    /** @var int */
    private $serverPort;

    /** @var bool */
    private $isEncrypted = false;

    /** @var mixed[] Array from stream_get_meta_data($this->socket)["crypto"] or an empty array. */
    private $cryptoInfo = [];

    /** @var \Generator */
    private $requestParser;

    /** @var string */
    private $readWatcher;

    /** @var string */
    private $writeWatcher;

    /** @var string */
    private $writeBuffer = "";

    /** @var int */
    private $status = 0;

    /** @var bool */
    private $isExported = false;

    /** @var \Aerys\Options */
    private $options;

    /** @var \Aerys\HttpDriver */
    private $httpDriver;

    /** @var \Aerys\Responder */
    private $responder;

    /** @var \Aerys\ErrorHandler */
    private $errorHandler;

    /** @var callable[] */
    private $onClose = [];

    /** @var \Aerys\TimeoutCache */
    private $timeoutCache;

    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    /** @var bool */
    private $paused = false;

    /** @var \Amp\Deferred|null */
    private $writeDeferred;

    /** @var callable */
    private $resume;

    /**
     * @param resource $socket Stream socket resource.
     * @param \Aerys\Responder $responder
     * @param \Aerys\ErrorHandler $errorHandler
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Aerys\Options $options
     * @param \cash\LRUCache $cache
     */
    public function __construct(
        /* resource */ $socket,
        Responder $responder,
        ErrorHandler $errorHandler,
        PsrLogger $logger,
        Options $options,
        TimeoutCache $timeoutCache
    ) {
        \stream_set_blocking($socket, false);

        $this->socket = $socket;
        $this->id = (int) $socket;

        $this->options = $options;
        $this->timeoutCache = $timeoutCache;
        $this->logger = $logger;
        $this->responder = $responder;
        $this->errorHandler = $errorHandler;

        $serverName = \stream_socket_get_name($this->socket, false);
        if ($portStartPos = \strrpos($serverName, ":")) {
            $this->serverAddress = substr($serverName, 0, $portStartPos);
            $this->serverPort = (int) substr($serverName, $portStartPos + 1);
        } else {
            $this->serverAddress = $serverName;
            $this->serverPort = 0;
        }

        $peerName = \stream_socket_get_name($this->socket, true);
        if ($portStartPos = \strrpos($peerName, ":")) {
            $this->clientAddress = substr($peerName, 0, $portStartPos);
            $this->clientPort = (int) substr($peerName, $portStartPos + 1);
            $this->clientNet = @\inet_pton($this->clientAddress);
            if (isset($this->clientNet[4])) {
                $this->clientNet = \substr($this->clientNet, 0, 7 /* /56 block */);
            }
        } else {
            $this->clientAddress = $serverName;
            $this->clientPort = 0;
            $this->clientNet = $serverName;
        }

        $this->remainingRequests = $this->options->getMaxRequestsPerConnection();

        $this->resume = $this->callableFromInstanceMethod("resume");
    }

    /**
     * Listen for requests on the client and parse them using the given HTTP driver.
     *
     * @param \Aerys\HttpDriver $driver
     *
     * @throws \Error If the client has already been started.
     */
    public function start(HttpDriver $driver) {
        if ($this->httpDriver) {
            throw new \Error("Client already started");
        }

        $this->timeoutCache->renew($this->id);

        $this->httpDriver = $driver;
        $this->httpDriver->setup(
            $this,
            $this->callableFromInstanceMethod("onMessage"),
            $this->callableFromInstanceMethod("onError"),
            $this->callableFromInstanceMethod("write"),
            $this->callableFromInstanceMethod("pause")
        );

        $this->requestParser = $this->httpDriver->parser();
        $this->requestParser->current();

        $this->writeWatcher = Loop::onWritable($this->socket, $this->callableFromInstanceMethod("onWritable"));
        Loop::disable($this->writeWatcher);

        $context = \stream_context_get_options($this->socket);
        if (isset($context["ssl"])) {
            $this->readWatcher = Loop::onReadable($this->socket, $this->callableFromInstanceMethod("negotiateCrypto"));
            return;
        }

        $this->readWatcher = Loop::onReadable($this->socket, $this->callableFromInstanceMethod("onReadable"));
    }

    /**
     * @return \Aerys\Options Server options object.
     */
    public function getOptions(): Options {
        return $this->options;
    }

    /**
     * @return bool
     */
    public function waitingOnResponse(): bool {
        return $this->httpDriver->pendingResponseCount() > $this->httpDriver->pendingRequestCount();
    }

    /**
     * Integer ID of this client.
     *
     * @return int
     */
    public function getId(): int {
        return $this->id;
    }

    /**
     * @return string Remote IP address.
     */
    public function getRemoteAddress(): string {
        return $this->clientAddress;
    }

    /**
     * @return int Remote port number.
     */
    public function getRemotePort(): int {
        return $this->clientPort;
    }

    /**
     * @return string Local server IP address.
     */
    public function getLocalAddress(): string {
        return $this->serverAddress;
    }

    /**
     * @return int Local server port.
     */
    public function getLocalPort(): int {
        return $this->serverPort;
    }

    /**
     * @return bool True if the client is encrypted, false if plaintext.
     */
    public function isEncrypted(): bool {
        return $this->isEncrypted;
    }

    /**
     * If the client is encrypted, returns the array returned from stream_get_meta_data($this->socket)["crypto"].
     * Otherwise returns an empty array.
     *
     * @return array
     */
    public function getCryptoContext(): array {
        return $this->cryptoInfo;
    }

    /**
     * @return string Unique network ID based on IP for matching the client with other clients from the same IP.
     */
    public function getNetworkId(): string {
        return $this->clientNet;
    }

    /**
     * @return int Integer mask of Client::CLOSED_* constants.
     */
    public function getStatus(): int {
        return $this->status;
    }

    /**
     * Forcefully closes the client connection.
     */
    public function close() {
        $this->clear();

        if ($this->status === self::CLOSED_RDWR) {
            return; // Client already closed.
        }

        foreach ($this->onClose as $callback) {
            $callback($this);
        }
        $this->onClose = [];

        if ($this->writeDeferred) {
            $this->writeDeferred->fail(new ClientException("Client disconnected"));
        }

        // ensures a TCP FIN frame is sent even if other processes (forks) have inherited the fd
        @\stream_socket_shutdown($this->socket, \STREAM_SHUT_RDWR);
        @\fclose($this->socket);
        $this->status = self::CLOSED_RDWR;

        if ($this->serverAddress[0] !== "/") { // no unix domain socket
            \assert($this->logger->debug("Close {$this->clientAddress}:{$this->clientPort} #{$this->id}") || true);
        } else {
            \assert($this->logger->debug("Close connection on {$this->serverAddress} #{$this->id}") || true);
        }

        $this->httpDriver = null;
        $this->requestParser = null;
    }

    /**
     * Attaches a callback invoked with this client closes. The callback is passed this object as the first parameter.
     *
     * @param callable $callback
     */
    public function onClose(callable $callback) {
        $this->onClose[] = $callback;
    }

    private function clear() {
        $this->httpDriver = null;
        $this->requestParser = null;

        if ($this->readWatcher) {
            Loop::cancel($this->readWatcher);
        }

        if ($this->writeWatcher) {
            Loop::cancel($this->writeWatcher);
        }

        $this->timeoutCache->clear($this->id);
    }

    private function onReadable(string $watcherId, $socket) {
        $data = @\stream_get_contents($socket, $this->options->getIoGranularity());
        if ($data !== false && $data !== "") {
            $this->timeoutCache->renew($this->id);
            $this->requestParser->send($data);
            return;
        }

        if (!\is_resource($socket) || @\feof($socket)) {
            if ($this->status === self::CLOSED_WR || !$this->waitingOnResponse()) {
                $this->close();
                return;
            }

            $this->status = self::CLOSED_RD;
            Loop::cancel($watcherId);
        }
    }

    private function negotiateCrypto(string $watcherId, $socket) {
        if ($handshake = @\stream_socket_enable_crypto($socket, true)) {
            Loop::cancel($watcherId);

            $this->isEncrypted = true;
            $this->cryptoInfo = \stream_get_meta_data($this->socket)["crypto"];

            \assert($this->logger->debug(\sprintf(
                "Crypto negotiated %s%s:%d",
                ($this->cryptoInfo["alpn_protocol"] ?? null === "h2" ? "(h2) " : ""),
                $this->clientAddress,
                $this->clientPort
            )) || true);

            $this->readWatcher = Loop::onReadable($this->socket, $this->callableFromInstanceMethod("onReadable"));
            return;
        }

        if ($handshake === false) {
            \assert($this->logger->debug("Crypto handshake error {$this->clientAddress}:{$this->clientPort}") || true);
            $this->close();
        }
    }

    private function onWritable() {
        $this->writeBuffer = $this->send($this->writeBuffer);

        if ($this->writeBuffer === "") {
            Loop::disable($this->writeWatcher);

            if ($this->writeDeferred) {
                $deferred = $this->writeDeferred;
                $this->writeDeferred = null;
                $deferred->resolve();
            }
        }
    }

    private function write(string $data, bool $close = false): Promise {
        $this->writeBuffer = $this->send($data);

        if ($this->writeBuffer !== "") {
            Loop::enable($this->writeWatcher);

            if ($this->writeDeferred === null) {
                $this->writeDeferred = new Deferred;
            }

            $promise = $this->writeDeferred->promise();

            if ($close) {
                $promise->onResolve([$this, "close"]);
            }

            return $promise;
        }

        if ($close) {
            $this->close();
        }

        return new Success;
    }

    private function send(string $data): string {
        $bytesWritten = @\fwrite($this->socket, $data);
        if ($bytesWritten === false
            || ($bytesWritten === 0 && (!\is_resource($this->socket) || @\feof($this->socket)))
        ) {
            if ($this->status === self::CLOSED_RD) {
                $this->close();
            } else {
                $this->status = self::CLOSED_WR;
                Loop::cancel($this->writeWatcher);
            }
            return "";
        }

        if ($bytesWritten === \strlen($data)) {
            return "";
        }

        return \substr($data, $bytesWritten);
    }

    private function onMessage(Request $request): Promise {
        \assert($this->logger->debug(sprintf(
            "%s %s HTTP/%s @ %s:%s",
            $request->getMethod(),
            $request->getUri(),
            $request->getProtocolVersion(),
            $this->clientAddress,
            $this->clientPort
        )) || true);

        return new Coroutine($this->respond($request));
    }

    private function onError(int $status, string $message): Promise {
        $this->pause();

        \assert($this->logger->debug(
            "Client parse error on {$this->clientAddress}:{$this->clientPort}: {$message}"
        ) || true);

        return new Coroutine($this->sendErrorResponse($status, $message));
    }

    private function pause(): callable {
        $this->paused = true;
        Loop::disable($this->readWatcher);
        return $this->resume;
    }

    private function resume() {
        if ($this->paused && !($this->status & self::CLOSED_RD)) {
            Loop::enable($this->readWatcher);
        }

        $this->paused = false;
    }

    /**
     * Respond to a parse error when parsing a client message.
     *
     * @param int $status
     * @param string $reason
     *
     * @return \Generator
     */
    private function sendErrorResponse(int $status, string $reason): \Generator {
        /** @var \Aerys\Response $response */
        try {
            $response = yield $this->errorHandler->handle($status, $reason);
        } catch (\Throwable $exception) {
            $response = yield $this->makeExceptionResponse($exception);
        }

        $response->setHeader("connection", "close");

        yield from $this->sendResponse($response);
    }

    /**
     * Respond to a parsed request.
     *
     * @param \Aerys\Request $request
     *
     * @return \Generator
     */
    private function respond(Request $request): \Generator {
        try {
            $method = $request->getMethod();

            if (!\in_array($method, $this->options->getAllowedMethods(), true)) {
                if (!\in_array($method, HttpDriver::KNOWN_METHODS, true)) {
                    $response = yield from $this->makeNotImplementedResponse();
                } else {
                    $response = yield from $this->makeMethodNotAllowedResponse();
                }
            } elseif ($method === "OPTIONS" && $request->getTarget() === "*") {
                $response = $this->makeOptionsResponse();
            } else {
                $response = yield $this->responder->respond($request);

                if (!$response instanceof Response) {
                    throw new \Error("At least one request handler must return an instance of " . Response::class);
                }
            }
        } catch (ClientException $exception) {
            return; // Handled in code that generated the ClientException, response already sent.
        } catch (\Throwable $error) {
            $this->logger->error($error);
            $response = yield $this->makeExceptionResponse($error, $request);
        }

        yield from $this->sendResponse($response, $request);
    }

    private function makeServiceUnavailableResponse(): \Generator {
        $status = HttpStatus::SERVICE_UNAVAILABLE;
        /** @var \Aerys\Response $response */
        $response = yield $this->errorHandler->handle($status, HttpStatus::getReason($status));
        $response->setHeader("Connection", "close");
        return $response;
    }

    private function makeMethodNotAllowedResponse(): \Generator {
        $status = HttpStatus::METHOD_NOT_ALLOWED;
        /** @var \Aerys\Response $response */
        $response = yield $this->errorHandler->handle($status, HttpStatus::getReason($status));
        $response->setHeader("Connection", "close");
        $response->setHeader("Allow", \implode(", ", $this->options->getAllowedMethods()));
        return $response;
    }

    private function makeNotImplementedResponse(): \Generator {
        $status = HttpStatus::NOT_IMPLEMENTED;
        /** @var \Aerys\Response $response */
        $response = yield $this->errorHandler->handle($status, HttpStatus::getReason($status));
        $response->setHeader("Connection", "close");
        $response->setHeader("Allow", \implode(", ", $this->options->getAllowedMethods()));
        return $response;
    }

    private function makeOptionsResponse(): Response {
        return new Response\EmptyResponse(["Allow" => implode(", ", $this->options->getAllowedMethods())]);
    }

    /**
     * Send the response to the client.
     *
     * @param \Aerys\Response $response
     * @param \Aerys\Request|null $request
     *
     * @return \Generator
     */
    private function sendResponse(Response $response, Request $request = null): \Generator {
        $responseWriter = $this->httpDriver->writer($response, $request);

        $body = $response->getBody();

        try {
            do {
                $chunk = yield $body->read();

                if ($this->status & self::CLOSED_WR) {
                    $responseWriter->send(null);
                    return;
                }

                $responseWriter->send($chunk); // Sends null when stream closes.
            } while ($chunk !== null);
        } catch (ClientException $exception) {
            return;
        } catch (\Throwable $exception) {
            // Reading response body failed, abort writing the response to the client.
            $this->logger->error($exception);
            $responseWriter->send(null);
        }

        if ($this->status === self::CLOSED_RD && !$this->waitingOnResponse()) {
            if ($this->writeDeferred) {
                $this->writeDeferred->promise()->onResolve([$this, "close"]);
                return;
            }

            $this->close();
            return;
        }

        if (!($this->status & self::CLOSED_RD)) {
            $this->timeoutCache->renew($this->id);
        }

        try {
            if ($response->isDetached()) {
                $responseWriter->send(null);
                $this->export($response);
            }
        } catch (\Throwable $exception) {
            $this->logger->error($exception);
        }
    }

    /**
     * @param \Throwable $exception
     * @param \Aerys\Request $request
     *
     * @return \Amp\Promise<\Aerys\Response>
     */
    private function makeExceptionResponse(\Throwable $exception, Request $request = null): Promise {
        $status = HttpStatus::INTERNAL_SERVER_ERROR;

        // Return an HTML page with the exception in debug mode.
        if ($request !== null && $this->options->isInDebugMode()) {
            $html = \str_replace(
                ["{uri}", "{class}", "{message}", "{file}", "{line}", "{trace}"],
                \array_map("htmlspecialchars", [
                    $request->getUri(),
                    \get_class($exception),
                    $exception->getMessage(),
                    $exception->getFile(),
                    $exception->getLine(),
                    $exception->getTraceAsString()
                ]),
                INTERNAL_SERVER_ERROR_HTML
            );

            return new Success(new Response\HtmlResponse($html, [], $status));
        }

        try {
            // Return a response defined by the error handler in production mode.
            return $this->errorHandler->handle($status, HttpStatus::getReason($status), $request);
        } catch (\Throwable $exception) {
            // If the error handler throws, fallback to returning the default HTML error page.
            $this->logger->error($exception);

            $html = \str_replace(
                ["{code}", "{reason}"],
                \array_map("htmlspecialchars", [$status, HttpStatus::getReason($status)]),
                DEFAULT_ERROR_HTML
            );

            return new Success(new Response\HtmlResponse($html, [], $status));
        }
    }

    /**
     * Invokes the export function on Response with the socket detached from the HTTP server.
     *
     * @param \Aerys\Response $response
     */
    private function export(Response $response) {
        $this->clear();
        $this->isExported = true;

        \assert($this->logger->debug("Export {$this->clientAddress}:{$this->clientPort}") || true);

        $response->export(new Internal\DetachedSocket($this, $this->socket));
    }
}

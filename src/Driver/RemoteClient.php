<?php

namespace Amp\Http\Server\Driver;

use Amp\CallableMaker;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Failure;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;
use Psr\Log\LoggerInterface as PsrLogger;

final class RemoteClient implements Client {
    use CallableMaker;

    /** @var DefaultErrorHandler */
    private static $defaultErrorHandler;

    /** @var int */
    private $id;

    /** @var resource Stream socket resource */
    private $socket;

    /** @var string */
    private $clientAddress;

    /** @var int|null */
    private $clientPort;

    /** @var string */
    private $serverAddress;

    /** @var int|null */
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

    /** @var Options */
    private $options;

    /** @var HttpDriver */
    private $httpDriver;

    /** @var RequestHandler */
    private $requestHandler;

    /** @var ErrorHandler */
    private $errorHandler;

    /** @var callable[]|null */
    private $onClose = [];

    /** @var TimeoutCache */
    private $timeoutCache;

    /** @var PsrLogger */
    private $logger;

    /** @var Deferred|null */
    private $writeDeferred;

    /** @var int */
    private $pendingResponses = 0;

    /** @var bool  */
    private $paused = false;

    /** @var callable */
    private $resume;

    /**
     * @param resource       $socket Stream socket resource.
     * @param RequestHandler $requestHandler
     * @param ErrorHandler   $errorHandler
     * @param PsrLogger      $logger
     * @param Options        $options
     * @param TimeoutCache   $timeoutCache
     */
    public function __construct(
        /* resource */ $socket,
        RequestHandler $requestHandler,
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
        $this->requestHandler = $requestHandler;
        $this->errorHandler = $errorHandler;

        if (!self::$defaultErrorHandler) {
            self::$defaultErrorHandler = new DefaultErrorHandler;
        }

        $serverName = \stream_socket_get_name($this->socket, false);
        if ($portStartPos = \strrpos($serverName, ":")) {
            $this->serverAddress = substr($serverName, 0, $portStartPos);
            $this->serverPort = (int) substr($serverName, $portStartPos + 1);
        } else {
            $this->serverAddress = $serverName;
        }

        $peerName = \stream_socket_get_name($this->socket, true);
        if ($portStartPos = \strrpos($peerName, ":")) {
            $this->clientAddress = substr($peerName, 0, $portStartPos);
            $this->clientPort = (int) substr($peerName, $portStartPos + 1);
        } else {
            $this->clientAddress = $serverName;
        }

        $this->resume = $this->callableFromInstanceMethod("resume");
    }

    /**
     * Listen for requests on the client and parse them using the given HTTP driver.
     *
     * @param \Amp\Http\Server\Driver\HttpDriverFactory $driverFactory
     *
     * @throws \Error If the client has already been started.
     */
    public function start(HttpDriverFactory $driverFactory) {
        if ($this->readWatcher) {
            throw new \Error("Client already started");
        }

        $this->timeoutCache->renew($this->id);

        $this->writeWatcher = Loop::onWritable($this->socket, $this->callableFromInstanceMethod("onWritable"));
        Loop::disable($this->writeWatcher);

        $context = \stream_context_get_options($this->socket);
        if (isset($context["ssl"])) {
            $this->readWatcher = Loop::onReadable(
                $this->socket,
                $this->callableFromInstanceMethod("negotiateCrypto"),
                $driverFactory
            );
            return;
        }

        $this->setup($driverFactory->selectDriver($this));

        $this->readWatcher = Loop::onReadable($this->socket, $this->callableFromInstanceMethod("onReadable"));
    }

    /**
     * @param HttpDriver $driver
     */
    private function setup(HttpDriver $driver) {
        $this->httpDriver = $driver;
        $this->requestParser = $this->httpDriver->setup(
            $this,
            $this->callableFromInstanceMethod("onMessage"),
            $this->callableFromInstanceMethod("write")
        );

        $this->requestParser->current();
    }

    /** @inheritdoc */
    public function getOptions(): Options {
        return $this->options;
    }

    /** @inheritdoc */
    public function getPendingResponseCount(): int {
        return $this->pendingResponses;
    }

    /** @inheritdoc */
    public function getPendingRequestCount(): int {
        if ($this->httpDriver === null) {
            return 0;
        }

        return $this->httpDriver->getPendingRequestCount();
    }

    /** @inheritdoc */
    public function isWaitingOnResponse(): bool {
        return $this->httpDriver !== null && $this->pendingResponses > $this->httpDriver->getPendingRequestCount();
    }

    /** @inheritdoc */
    public function getId(): int {
        return $this->id;
    }

    /** @inheritdoc */
    public function getRemoteAddress(): string {
        return $this->clientAddress;
    }

    /** @inheritdoc */
    public function getRemotePort() {
        return $this->clientPort;
    }

    /** @inheritdoc */
    public function getLocalAddress(): string {
        return $this->serverAddress;
    }

    /** @inheritdoc */
    public function getLocalPort() {
        return $this->serverPort;
    }

    /** @inheritdoc */
    public function isUnix(): bool {
        return $this->serverPort === 0;
    }

    /** @inheritdoc */
    public function isEncrypted(): bool {
        return $this->isEncrypted;
    }

    /** @inheritdoc */
    public function getCryptoContext(): array {
        return $this->cryptoInfo;
    }

    /** @inheritdoc */
    public function isExported(): bool {
        return $this->isExported;
    }

    /** @inheritdoc */
    public function getStatus(): int {
        return $this->status;
    }

    /** @inheritdoc */
    public function close() {
        if ($this->onClose === null) {
            return; // Client already closed.
        }

        $onClose = $this->onClose;
        $this->onClose = null;

        $this->status = self::CLOSED_RDWR;

        $this->clear();

        if ($this->writeDeferred) {
            $this->writeDeferred->resolve();
        }

        // ensures a TCP FIN frame is sent even if other processes (forks) have inherited the fd
        @\stream_socket_shutdown($this->socket, \STREAM_SHUT_RDWR);
        @\fclose($this->socket);

        if ($this->serverAddress[0] !== "/") { // no unix domain socket
            \assert($this->logger->debug("Close {$this->clientAddress}:{$this->clientPort} #{$this->id}") || true);
        } else {
            \assert($this->logger->debug("Close connection on {$this->serverAddress} #{$this->id}") || true);
        }

        foreach ($onClose as $callback) {
            $callback($this);
        }
    }

    /** @inheritdoc */
    public function onClose(callable $callback) {
        if ($this->onClose === null) {
            $callback($this);
            return;
        }

        $this->onClose[] = $callback;
    }

    /** @inheritdoc */
    public function stop(int $timeout): Promise {
        if ($this->httpDriver === null) {
            $this->close();
            return new Success;
        }

        $promise = Promise\timeout($this->httpDriver->stop(), $timeout);
        $promise->onResolve([$this, "close"]);
        return $promise;
    }

    private function clear() {
        $this->httpDriver = null;
        $this->requestParser = null;
        $this->resume = null;
        $this->paused = true;

        if ($this->readWatcher) {
            Loop::cancel($this->readWatcher);
        }

        if ($this->writeWatcher) {
            Loop::cancel($this->writeWatcher);
        }

        $this->timeoutCache->clear($this->id);
    }

    /**
     * Called by the onReadable watcher (after encryption has been negotiated if applicable).
     */
    private function onReadable() {
        $data = @\stream_get_contents($this->socket, $this->options->getChunkSize());
        if ($data !== false && $data !== "") {
            $this->timeoutCache->renew($this->id);
            $this->parse($data);
            return;
        }

        if (!\is_resource($this->socket) || @\feof($this->socket)) {
            $this->close();
        }
    }

    /**
     * Sends data to the request parser.
     *
     * @param string $data
     */
    private function parse(string $data = "") {
        try {
            $promise = $this->requestParser->send($data);

            if ($promise instanceof Promise && !$this->isExported && !($this->status & self::CLOSED_RDWR)) {
                // Parser wants to wait until a promise completes.
                $this->paused = true;
                $promise->onResolve($this->resume); // Resume will set $this->paused to false if called immediately.
                if ($this->paused) { // Avoids potential for unnecessary disable followed by enable.
                    Loop::disable($this->readWatcher);
                }
            }
        } catch (\Throwable $exception) {
            // Parser *should not* throw an exception, but in case it does...
            $this->logger->critical($exception);
            $this->close();
        }
    }

    /**
     * Called by the onReadable watcher after the client connects until encryption is enabled.
     *
     * @param string                                    $watcher
     * @param resource                                  $socket
     * @param \Amp\Http\Server\Driver\HttpDriverFactory $driverFactory
     */
    private function negotiateCrypto(string $watcher, $socket, HttpDriverFactory $driverFactory) {
        if ($handshake = @\stream_socket_enable_crypto($this->socket, true)) {
            Loop::cancel($this->readWatcher);

            $this->isEncrypted = true;
            $this->cryptoInfo = \stream_get_meta_data($this->socket)["crypto"];

            \assert($this->logger->debug(\sprintf(
                "Crypto negotiated (ALPN: %s) %s:%d",
                ($this->cryptoInfo["alpn_protocol"] ?? "none"),
                $this->clientAddress,
                $this->clientPort
            )) || true);

            $this->setup($driverFactory->selectDriver($this));

            $this->readWatcher = Loop::onReadable($this->socket, $this->callableFromInstanceMethod("onReadable"));
            return;
        }

        if ($handshake === false) {
            \assert($this->logger->debug("Crypto handshake error {$this->clientAddress}:{$this->clientPort}") || true);
            $this->close();
        }
    }

    /**
     * Called by the onWritable watcher.
     */
    private function onWritable() {
        $bytesWritten = @\fwrite($this->socket, $this->writeBuffer);

        if ($bytesWritten === false) {
            $this->close();
            return;
        }

        if ($bytesWritten === 0) {
            return;
        }

        $this->timeoutCache->renew($this->id);

        if ($bytesWritten !== \strlen($this->writeBuffer)) {
            $this->writeBuffer = \substr($this->writeBuffer, $bytesWritten);
            return;
        }

        $this->writeBuffer = "";

        Loop::disable($this->writeWatcher);
        $deferred = $this->writeDeferred;
        $this->writeDeferred = null;
        $deferred->resolve();
    }

    /**
     * Adds the given data to the buffer of data to be written to the client socket. Returns a promise that resolves
     * once the client write buffer has emptied.
     *
     * @param string $data The data to write.
     * @param bool $close If true, close the client after the given chunk of data has been written.
     *
     * @return \Amp\Promise
     */
    private function write(string $data, bool $close = false): Promise {
        if ($this->status & self::CLOSED_WR) {
            return new Failure(new ClientException("The client disconnected"));
        }

        if (!$this->writeDeferred) {
            $bytesWritten = @\fwrite($this->socket, $data);

            if ($bytesWritten === false) {
                $this->close();
                return new Failure(new ClientException("The client disconnected"));
            }

            if ($bytesWritten === \strlen($data)) {
                if ($close) {
                    $this->close();
                }

                return new Success;
            }

            $this->writeDeferred = new Deferred;
            $data = \substr($data, $bytesWritten);
        }

        $this->writeBuffer .= $data;

        Loop::enable($this->writeWatcher);

        $promise = $this->writeDeferred->promise();

        if ($close) {
            Loop::cancel($this->readWatcher);
            $this->status |= self::CLOSED_WR;
            $promise->onResolve([$this, "close"]);
        }

        return $promise;
    }

    /**
     * Invoked by the HTTP parser when a request is parsed.
     *
     * @param Request $request
     *
     * @return \Amp\Promise
     */
    private function onMessage(Request $request): Promise {
        \assert($this->logger->debug(sprintf(
            "%s %s HTTP/%s @ %s:%s",
            $request->getMethod(),
            $request->getUri(),
            $request->getProtocolVersion(),
            $this->clientAddress,
            $this->clientPort
        )) || true);

        $this->pendingResponses++;

        return new Coroutine($this->respond($request));
    }

    /**
     * Resumes the request parser after it has yielded a promise.
     *
     * @param \Throwable|null $exception
     */
    private function resume(\Throwable $exception = null) {
        if ($exception) {
            $this->close();
            return;
        }

        if (!$this->isExported && !($this->status & self::CLOSED_RDWR)) {
            $this->paused = false;
            Loop::enable($this->readWatcher);
            $this->parse();
        }
    }

    /**
     * Respond to a parsed request.
     *
     * @param Request $request
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
            } elseif ($method === "OPTIONS" && $request->getUri()->getPath() === "") {
                $response = $this->makeOptionsResponse();
            } else {
                $response = yield $this->requestHandler->handleRequest(clone $request);

                if (!$response instanceof Response) {
                    throw new \Error("At least one request handler must return an instance of " . Response::class);
                }
            }
        } catch (ClientException $exception) {
            $this->close();
            return;
        } catch (\Throwable $exception) {
            $this->logger->error($exception);
            $response = yield from $this->makeExceptionResponse($request);
        } finally {
            $this->pendingResponses--;
        }

        if ($this->status & self::CLOSED_WR) {
            return; // Client closed before response could be sent.
        }

        $promise = $this->httpDriver->write($request, $response);

        if ($response->isUpgraded()) {
            yield $promise; // Wait on writing response when the response is an upgrade response.
            $this->export($response->getUpgradeCallable());
        }
    }

    private function makeMethodNotAllowedResponse(): \Generator {
        $status = Status::METHOD_NOT_ALLOWED;
        /** @var \Amp\Http\Server\Response $response */
        $response = yield $this->errorHandler->handleError($status);
        $response->setHeader("Connection", "close");
        $response->setHeader("Allow", \implode(", ", $this->options->getAllowedMethods()));
        return $response;
    }

    private function makeNotImplementedResponse(): \Generator {
        $status = Status::NOT_IMPLEMENTED;
        /** @var \Amp\Http\Server\Response $response */
        $response = yield $this->errorHandler->handleError($status);
        $response->setHeader("Connection", "close");
        $response->setHeader("Allow", \implode(", ", $this->options->getAllowedMethods()));
        return $response;
    }

    private function makeOptionsResponse(): Response {
        return new Response(Status::NO_CONTENT, ["Allow" => implode(", ", $this->options->getAllowedMethods())]);
    }

    /**
     * Used if an exception is thrown from a request handler.
     *
     * @param Request $request
     *
     * @return \Generator
     */
    private function makeExceptionResponse(Request $request): \Generator {
        $status = Status::INTERNAL_SERVER_ERROR;

        try {
            return yield $this->errorHandler->handleError($status, null, $request);
        } catch (\Throwable $exception) {
            // If the error handler throws, fallback to returning the default HTML error page.
            $this->logger->error($exception);

            // The default error handler will never throw, otherwise there's a bug
            return yield self::$defaultErrorHandler->handleError($status, null, $request);
        }
    }

    /**
     * Invokes the export function on Response with the socket detached from the HTTP server.
     *
     * @param callable $upgrade callable
     */
    private function export(callable $upgrade) {
        if ($this->status & self::CLOSED_RDWR || $this->isExported) {
            return;
        }

        $this->clear();
        $this->isExported = true;

        \assert($this->logger->debug("Upgrade {$this->clientAddress}:{$this->clientPort} #{$this->id}") || true);

        try {
            $upgrade(new Internal\DetachedSocket($this, $this->socket, $this->options->getChunkSize()));
        } catch (\Throwable $exception) {
            $this->logger->error($exception);
            $this->close();
        }
    }
}

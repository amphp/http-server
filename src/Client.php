<?php

namespace Amp\Http\Server;

use Amp\CallableMaker;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Failure;
use Amp\Http\Status;
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
    private $clientNetworkId;

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

    /** @var \Amp\Http\Server\Options */
    private $options;

    /** @var \Amp\Http\Server\HttpDriver */
    private $httpDriver;

    /** @var \Amp\Http\Server\Responder */
    private $responder;

    /** @var \Amp\Http\Server\ErrorHandler */
    private $errorHandler;

    /** @var callable[]|null */
    private $onClose = [];

    /** @var \Amp\Http\Server\TimeoutCache */
    private $timeoutCache;

    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    /** @var \Amp\Deferred|null */
    private $writeDeferred;

    /** @var int */
    private $pendingResponses = 0;

    /** @var bool  */
    private $paused = false;

    /** @var callable */
    private $resume;

    /**
     * @param resource $socket Stream socket resource.
     * @param \Amp\Http\Server\Responder $responder
     * @param \Amp\Http\Server\ErrorHandler $errorHandler
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Amp\Http\Server\Options $options
     * @param \Amp\Http\Server\TimeoutCache $timeoutCache
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
            $this->clientNetworkId = @\inet_pton($this->clientAddress);
            if (isset($this->clientNetworkId[4])) {
                $this->clientNetworkId = \substr($this->clientNetworkId, 0, 7 /* /56 block */);
            }
        } else {
            $this->clientAddress = $serverName;
            $this->clientPort = 0;
            $this->clientNetworkId = $serverName;
        }

        $this->resume = $this->callableFromInstanceMethod("resume");
    }

    /**
     * Listen for requests on the client and parse them using the given HTTP driver.
     *
     * @param \Amp\Http\Server\HttpDriverFactory $driverFactory
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
     * @param \Amp\Http\Server\HttpDriver $driver
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

    /**
     * @return \Amp\Http\Server\Options Server options object.
     */
    public function getOptions(): Options {
        return $this->options;
    }

    /**
     * @return int Number of requests with pending responses.
     */
    public function pendingResponseCount(): int {
        return $this->pendingResponses;
    }

    /**
     * @return int Number of requests being read.
     */
    public function pendingRequestCount(): int {
        if ($this->httpDriver === null) {
            return 0;
        }

        return $this->httpDriver->pendingRequestCount();
    }

    /**
     * @return bool `true` if the number of pending responses is greater than the number of pending requests.
     *     Useful for determining if a responder is actively writing a response or if a request is taking too
     *     long to arrive.
     */
    public function waitingOnResponse(): bool {
        return $this->httpDriver !== null && $this->pendingResponses > $this->httpDriver->pendingRequestCount();
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
     * @return bool `true` if this client is connected via an unix domain socket.
     */
    public function isUnix(): bool {
        return $this->serverPort === 0;
    }

    /**
     * @return bool `true` if the client is encrypted, `false` if plaintext.
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
     * @return bool `true` if the client has been exported from the server using Response::detach().
     */
    public function isExported(): bool {
        return $this->isExported;
    }

    /**
     * @return string Unique network ID based on IP for matching the client with other clients from the same IP.
     */
    public function getNetworkId(): string {
        return $this->clientNetworkId;
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

    /**
     * Attaches a callback invoked with this client closes. The callback is passed this object as the first parameter.
     *
     * @param callable $callback
     */
    public function onClose(callable $callback) {
        if ($this->onClose === null) {
            $callback($this);
            return;
        }

        $this->onClose[] = $callback;
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
        $data = @\stream_get_contents($this->socket, $this->options->getIoGranularity());
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
     * @param string $watcher
     * @param resource $socket
     * @param \Amp\Http\Server\HttpDriverFactory $driverFactory
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
     * @param \Amp\Http\Server\Request $request
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
     * @param \Amp\Http\Server\Request $request
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
                $response = yield $this->responder->respond(clone $request);

                if (!$response instanceof Response) {
                    throw new \Error("At least one request handler must return an instance of " . Response::class);
                }
            }
        } catch (ClientException $exception) {
            $this->close();
            return;
        } catch (\Throwable $error) {
            $this->logger->error($error);
            $response = yield from $this->makeExceptionResponse($error, $request);
        } finally {
            $this->pendingResponses--;
        }

        if ($this->status & self::CLOSED_WR) {
            return; // Client closed before response could be sent.f
        }

        $promise = $this->httpDriver->writer($response, $request);

        if ($response->isUpgraded()) {
            $callback = $response->getUpgradeCallable();
            $promise->onResolve(function () use ($callback) {
                $this->export($callback);
            });
        }
    }

    private function makeServiceUnavailableResponse(): \Generator {
        $status = Status::SERVICE_UNAVAILABLE;
        /** @var \Amp\Http\Server\Response $response */
        $response = yield $this->errorHandler->handle($status, Status::getReason($status));
        $response->setHeader("Connection", "close");
        return $response;
    }

    private function makeMethodNotAllowedResponse(): \Generator {
        $status = Status::METHOD_NOT_ALLOWED;
        /** @var \Amp\Http\Server\Response $response */
        $response = yield $this->errorHandler->handle($status, Status::getReason($status));
        $response->setHeader("Connection", "close");
        $response->setHeader("Allow", \implode(", ", $this->options->getAllowedMethods()));
        return $response;
    }

    private function makeNotImplementedResponse(): \Generator {
        $status = Status::NOT_IMPLEMENTED;
        /** @var \Amp\Http\Server\Response $response */
        $response = yield $this->errorHandler->handle($status, Status::getReason($status));
        $response->setHeader("Connection", "close");
        $response->setHeader("Allow", \implode(", ", $this->options->getAllowedMethods()));
        return $response;
    }

    private function makeOptionsResponse(): Response {
        return new Response\EmptyResponse(["Allow" => implode(", ", $this->options->getAllowedMethods())]);
    }

    /**
     * Used if an exception is thrown from a responder. Returns a response containing the exception stack trace
     * in debug mode or a response defined by the error handler in production mode.
     *
     * @param \Throwable $exception
     * @param \Amp\Http\Server\Request $request
     *
     * @return \Generator
     */
    private function makeExceptionResponse(\Throwable $exception, Request $request = null): \Generator {
        $status = Status::INTERNAL_SERVER_ERROR;

        // Return an HTML page with the exception in debug mode.
        if ($this->options->isInDebugMode()) {
            $html = \str_replace(
                ["{uri}", "{class}", "{message}", "{file}", "{line}", "{trace}"],
                \array_map("htmlspecialchars", [
                    $request ? $request->getUri() : "Exception thrown before request was fully read",
                    \get_class($exception),
                    $exception->getMessage(),
                    $exception->getFile(),
                    $exception->getLine(),
                    $exception->getTraceAsString()
                ]),
                INTERNAL_SERVER_ERROR_HTML
            );

            return new Response\HtmlResponse($html, [], $status);
        }

        try {
            // Return a response defined by the error handler in production mode.
            return yield $this->errorHandler->handle($status, Status::getReason($status), $request);
        } catch (\Throwable $exception) {
            // If the error handler throws, fallback to returning the default HTML error page.
            $this->logger->error($exception);

            $html = \str_replace(
                ["{code}", "{reason}"],
                \array_map("htmlspecialchars", [$status, Status::getReason($status)]),
                DEFAULT_ERROR_HTML
            );

            return new Response\HtmlResponse($html, [], $status);
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
            $upgrade(new Internal\DetachedSocket($this, $this->socket, $this->options->getIoGranularity()));
        } catch (\Throwable $exception) {
            $this->logger->error($exception);
            $this->close();
        }
    }
}

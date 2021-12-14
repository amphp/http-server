<?php

namespace Amp\Http\Server\Driver;

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
use Amp\Socket\ResourceSocket;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Amp\Success;
use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\call;

final class RemoteClient implements Client
{
    private const SHUTDOWN_TIMEOUT_ON_ERROR = 1000;

    /** @var DefaultErrorHandler */
    private static $defaultErrorHandler;

    /** @var int */
    private $id;

    /** @var resource Stream socket resource */
    private $socket;

    /** @var SocketAddress */
    private $clientAddress;

    /** @var SocketAddress */
    private $serverAddress;

    /** @var TlsInfo|null */
    private $tlsInfo;

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
    private $pendingHandlers = 0;

    /** @var int */
    private $pendingResponses = 0;

    /** @var bool */
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
        $socket,
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
        PsrLogger $logger,
        Options $options,
        TimeoutCache $timeoutCache
    ) {
        \stream_set_blocking($socket, false);
        \stream_set_read_buffer($socket, 0);

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

        $this->serverAddress = SocketAddress::fromLocalResource($this->socket);
        $this->clientAddress = SocketAddress::fromPeerResource($this->socket);

        $this->resume = \Closure::fromCallable([$this, 'resume']);
    }

    /**
     * Listen for requests on the client and parse them using the given HTTP driver.
     *
     * @param HttpDriverFactory $driverFactory
     *
     * @throws \Error If the client has already been started.
     */
    public function start(HttpDriverFactory $driverFactory): void
    {
        if ($this->readWatcher) {
            throw new \Error("Client already started");
        }


        $this->writeWatcher = Loop::onWritable($this->socket, \Closure::fromCallable([$this, 'onWritable']));
        Loop::disable($this->writeWatcher);

        $context = \stream_context_get_options($this->socket);
        if (isset($context["ssl"])) {
            $this->timeoutCache->update(
                $this->id,
                \time() + $this->options->getTlsSetupTimeout()
            );

            $this->readWatcher = Loop::onReadable(
                $this->socket,
                \Closure::fromCallable([$this, 'negotiateCrypto']),
                $driverFactory
            );

            return;
        }

        $this->setup($driverFactory->selectDriver(
            $this,
            $this->errorHandler,
            $this->logger,
            $this->options
        ));

        $this->readWatcher = Loop::onReadable($this->socket, \Closure::fromCallable([$this, 'onReadable']));
    }

    /** @inheritdoc */
    public function getOptions(): Options
    {
        return $this->options;
    }

    /** @inheritdoc */
    public function getPendingResponseCount(): int
    {
        return $this->pendingResponses;
    }

    /** @inheritdoc */
    public function getPendingRequestCount(): int
    {
        if ($this->httpDriver === null) {
            return 0;
        }

        return $this->httpDriver->getPendingRequestCount();
    }

    /** @inheritdoc */
    public function isWaitingOnResponse(): bool
    {
        return $this->httpDriver !== null && $this->pendingHandlers > $this->httpDriver->getPendingRequestCount();
    }

    /** @inheritdoc */
    public function getId(): int
    {
        return $this->id;
    }

    /** @inheritdoc */
    public function getRemoteAddress(): SocketAddress
    {
        return $this->clientAddress;
    }

    /** @inheritdoc */
    public function getLocalAddress(): SocketAddress
    {
        return $this->serverAddress;
    }

    /** @inheritdoc */
    public function isUnix(): bool
    {
        return $this->getRemoteAddress()->getPort() === null;
    }

    /** @inheritdoc */
    public function isEncrypted(): bool
    {
        return $this->tlsInfo !== null;
    }

    /** @inheritdoc */
    public function getTlsInfo(): ?TlsInfo
    {
        return $this->tlsInfo;
    }

    /** @inheritdoc */
    public function isExported(): bool
    {
        return $this->isExported;
    }

    /** @inheritdoc */
    public function getStatus(): int
    {
        return $this->status;
    }

    public function getExpirationTime(): int
    {
        return $this->timeoutCache->getExpirationTime($this->id) ?? 0;
    }

    public function updateExpirationTime(int $expiresAt): void
    {
        if ($this->onClose === null) {
            return; // Client closed.
        }

        $this->timeoutCache->update($this->id, $expiresAt);
    }

    /** @inheritdoc */
    public function close(): void
    {
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

        if (($this->serverAddress->getHost()[0] ?? "") !== "/") { // no unix domain socket
            \assert($this->logger->debug("Close {$this->clientAddress} #{$this->id}") || true);
        } else {
            \assert($this->logger->debug("Close connection on {$this->serverAddress} #{$this->id}") || true);
        }

        foreach ($onClose as $callback) {
            Promise\rethrow(call($callback, $this));
        }
    }

    /** @inheritdoc */
    public function onClose(callable $callback): void
    {
        if ($this->onClose === null) {
            Promise\rethrow(call($callback, $this));
            return;
        }

        $this->onClose[] = $callback;
    }

    /** @inheritdoc */
    public function stop(int $timeout): Promise
    {
        if ($this->httpDriver === null) {
            $this->close();
            return new Success;
        }

        $promise = Promise\timeout($this->httpDriver->stop(), $timeout);
        $promise->onResolve([$this, "close"]);
        return $promise;
    }

    /**
     * @param HttpDriver $driver
     */
    private function setup(HttpDriver $driver): void
    {
        $this->httpDriver = $driver;
        $this->requestParser = $this->httpDriver->setup(
            $this,
            \Closure::fromCallable([$this, 'onMessage']),
            \Closure::fromCallable([$this, 'write'])
        );

        $this->requestParser->current();
    }

    private function clear(): void
    {
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
    private function onReadable(): void
    {
        $data = @\stream_get_contents($this->socket, $this->options->getChunkSize());
        if ($data !== false && $data !== "") {
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
     * @param string|null $data
     */
    private function parse(?string $data = null): void
    {
        try {
            $promise = $this->requestParser->send($data);

            \assert($promise === null || $promise instanceof Promise);

            if ($promise instanceof Promise && !$this->isExported && !($this->status & self::CLOSED_RDWR)) {
                // Parser wants to wait until a promise completes.
                $this->paused = true;
                $promise->onResolve($this->resume); // Resume will set $this->paused to false if called immediately.
                if ($this->paused) { // Avoids potential for unnecessary disable followed by enable.
                    Loop::disable($this->readWatcher);
                }
            }

            if (!$this->paused) {
                // Trigger this again manually as there may be buffered data that will not trigger the watcher again.
                $this->onReadable();
            }
        } catch (\Throwable $exception) {
            // Parser *should not* throw an exception, but in case it does...
            $errorType = \get_class($exception);
            $this->logger->critical(
                "Unexpected {$errorType} while parsing request, closing connection.",
                ['exception' => $exception]
            );

            $this->close();
        }
    }

    /**
     * Called by the onReadable watcher after the client connects until encryption is enabled.
     *
     * @param string            $watcher
     * @param resource          $socket
     * @param HttpDriverFactory $driverFactory
     */
    private function negotiateCrypto(string $watcher, $socket, HttpDriverFactory $driverFactory): void
    {
        if ($handshake = @\stream_socket_enable_crypto($this->socket, true)) {
            Loop::cancel($this->readWatcher);

            $this->tlsInfo = TlsInfo::fromStreamResource($this->socket);
            \assert($this->tlsInfo !== null);

            \assert($this->logger->debug(\sprintf(
                "TLS negotiated with %s (%s with %s, application protocol: %s)",
                $this->clientAddress->toString(),
                $this->tlsInfo->getVersion(),
                $this->tlsInfo->getCipherName(),
                $this->tlsInfo->getApplicationLayerProtocol() ?? "none"
            )) || true);

            $this->setup($driverFactory->selectDriver(
                $this,
                $this->errorHandler,
                $this->logger,
                $this->options
            ));

            $this->readWatcher = Loop::onReadable($this->socket, \Closure::fromCallable([$this, 'onReadable']));
            return;
        }

        if ($handshake === false) {
            \assert($this->logger->debug(\sprintf(
                "TLS handshake error with %s: %s",
                $this->clientAddress,
                $this->cleanTlsErrorMessage(\error_get_last()['message'] ?? 'unknown error')
            )) || true);

            $this->close();
        }
    }

    /**
     * Called by the onWritable watcher.
     */
    private function onWritable(): void
    {
        $bytesWritten = @\fwrite($this->socket, $this->writeBuffer);

        if ($bytesWritten === false) {
            $this->close();
            return;
        }

        if ($bytesWritten === 0) {
            return;
        }

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
     * @param bool   $close If true, close the client after the given chunk of data has been written.
     *
     * @return Promise
     */
    private function write(string $data, bool $close = false): Promise
    {
        if ($this->status & self::CLOSED_WR) {
            return new Failure(new ClientException($this, "Client socket closed"));
        }

        if (!$this->writeDeferred) {
            $bytesWritten = @\fwrite($this->socket, $data);

            if ($bytesWritten === false) {
                $this->close();
                return new Failure(new ClientException($this, "Client socket closed"));
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
     * @param string  $buffer Remaining buffer in the parser.
     *
     * @return Promise
     */
    private function onMessage(Request $request, string $buffer = ''): Promise
    {
        \assert($this->logger->debug(\sprintf(
            "%s %s HTTP/%s @ %s",
            $request->getMethod(),
            $request->getUri(),
            $request->getProtocolVersion(),
            $this->clientAddress->toString()
        )) || true);

        return new Coroutine($this->respond($request, $buffer));
    }

    /**
     * Resumes the request parser after it has yielded a promise.
     *
     * @param \Throwable|null $exception
     */
    private function resume(\Throwable $exception = null): void
    {
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
     * @param string  $buffer
     *
     * @return \Generator
     */
    private function respond(Request $request, string $buffer): \Generator
    {
        $clientRequest = $request;
        $request = clone $request;

        $this->pendingHandlers++;
        $this->pendingResponses++;

        try {
            $method = $request->getMethod();

            if (!\in_array($method, $this->options->getAllowedMethods(), true)) {
                $response = yield from $this->makeMethodErrorResponse(
                    \in_array($method, HttpDriver::KNOWN_METHODS, true)
                        ? Status::METHOD_NOT_ALLOWED
                        : Status::NOT_IMPLEMENTED
                );
            } elseif ($method === "OPTIONS" && $request->getUri()->getPath() === "") {
                $response = $this->makeOptionsResponse();
            } else {
                $response = yield $this->requestHandler->handleRequest($request);

                if (!$response instanceof Response) {
                    throw new \Error(\sprintf(
                        "Promise returned from %s::handleRequest() must resolve to an instance of %s",
                        \get_class($this->requestHandler),
                        Response::class
                    ));
                }
            }
        } catch (ClientException $exception) {
            yield $this->stop(self::SHUTDOWN_TIMEOUT_ON_ERROR);
            $this->close();
            return;
        } catch (\Throwable $exception) {
            $errorType = \get_class($exception);
            $this->logger->error(
                "Unexpected {$errorType} thrown from RequestHandler::handleRequest(), falling back to error handler.",
                $this->createLogContext($exception, $request)
            );

            $response = yield from $this->makeExceptionResponse($request);
        } finally {
            $this->pendingHandlers--;
        }

        if ($this->status & self::CLOSED_WR) {
            return; // Client closed before response could be sent.
        }

        $promise = $this->httpDriver->write($clientRequest, $response);

        $promise->onResolve(function (): void {
            $this->pendingResponses--;
        });

        if ($response->isUpgraded()) {
            $this->isExported = true;
            $callback = $response->getUpgradeHandler();
            $promise->onResolve(function () use ($callback, $request, $response, $buffer): void {
                $this->export($callback, $request, $response, $buffer);
            });
        }
    }

    private function makeMethodErrorResponse(int $status): \Generator
    {
        /** @var Response $response */
        $response = yield $this->errorHandler->handleError($status);
        $response->setHeader("Allow", \implode(", ", $this->options->getAllowedMethods()));
        return $response;
    }

    private function makeOptionsResponse(): Response
    {
        return new Response(Status::NO_CONTENT, ["Allow" => \implode(", ", $this->options->getAllowedMethods())]);
    }

    /**
     * Used if an exception is thrown from a request handler.
     *
     * @param Request $request
     *
     * @return \Generator
     */
    private function makeExceptionResponse(Request $request): \Generator
    {
        $status = Status::INTERNAL_SERVER_ERROR;

        try {
            return yield $this->errorHandler->handleError($status, null, $request);
        } catch (\Throwable $exception) {
            // If the error handler throws, fallback to returning the default error page.
            $errorType = \get_class($exception);
            $this->logger->error(
                "Unexpected {$errorType} thrown from ErrorHandler::handleError(), falling back to default error handler.",
                $this->createLogContext($exception, $request)
            );

            // The default error handler will never throw, otherwise there's a bug
            return yield self::$defaultErrorHandler->handleError($status, null, $request);
        }
    }

    /**
     * Invokes the export function on Response with the socket upgraded from the HTTP server.
     *
     * @param callable $upgrade
     * @param Request  $request
     * @param Response $response
     * @param string   $buffer Remaining buffer read from the socket.
     */
    private function export(callable $upgrade, Request $request, Response $response, string $buffer): void
    {
        if ($this->status & self::CLOSED_RDWR) {
            return;
        }

        $this->clear();

        \assert($this->logger->debug("Upgrade {$this->clientAddress} #{$this->id}") || true);

        $socket = ResourceSocket::fromServerSocket($this->socket, $this->options->getChunkSize());
        $socket = new UpgradedSocket($this, $socket, $buffer);

        call($upgrade, $socket, $request, $response)->onResolve(function (?\Throwable $exception) use ($request): void {
            if (!$exception) {
                return;
            }

            $errorType = \get_class($exception);
            $this->logger->error(
                "Unexpected {$errorType} thrown during socket upgrade, closing connection.",
                $this->createLogContext($exception, $request)
            );

            $this->close();
        });
    }

    private function cleanTlsErrorMessage(string $message): string
    {
        $message = \str_replace('stream_socket_enable_crypto(): ', '', $message);
        $message = \str_replace('SSL operation failed with code ', 'TLS operation failed with code ', $message);
        $message = \str_replace('. OpenSSL Error messages', '', $message);

        return $message;
    }

    private function createLogContext(\Throwable $exception, Request $request): array
    {
        $logContext = ['exception' => $exception];
        if ($this->options->isRequestLogContextEnabled()) {
            $logContext['request'] = $request;
        }

        return $logContext;
    }
}

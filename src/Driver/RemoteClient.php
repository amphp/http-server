<?php

namespace Amp\Http\Server\Driver;

use Amp\Future;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Amp\TimeoutCancellation;
use Psr\Log\LoggerInterface as PsrLogger;
use Revolt\EventLoop;
use function Amp\async;

final class RemoteClient implements Client
{
    private const SHUTDOWN_TIMEOUT_ON_ERROR = 1;

    private static DefaultErrorHandler $defaultErrorHandler;

    private static $nextId = 0;

    private int $id;

    private ?TlsInfo $tlsInfo = null;

    private int $status = 0;

    private bool $isExported = false;

    private HttpDriver $httpDriver;

    /** @var callable[]|null */
    private ?array $onClose = [];

    private int $pendingHandlers = 0;

    private int $pendingResponses = 0;

    /**
     * @param Socket $socket
     * @param RequestHandler $requestHandler
     * @param ErrorHandler $errorHandler
     * @param PsrLogger $logger
     * @param Options $options
     * @param TimeoutCache $timeoutCache
     */
    public function __construct(
        private Socket $socket,
        private RequestHandler $requestHandler,
        private ErrorHandler $errorHandler,
        private PsrLogger $logger,
        private Options $options,
        private TimeoutCache $timeoutCache
    ) {
        self::$defaultErrorHandler ??= new DefaultErrorHandler;
        $this->id = self::$nextId++;
    }

    /**
     * Listen for requests on the client and parse them using the HTTP driver generated from the given factory.
     *
     * @param HttpDriverFactory $driverFactory
     *
     * @throws \Error If the client has already been started.
     */
    public function start(HttpDriverFactory $driverFactory): void
    {
        if (isset($this->httpDriver)) {
            throw new \Error("Client already started");
        }

        EventLoop::queue(function () use ($driverFactory): void {
            try {
                $context = \stream_context_get_options($this->socket->getResource());
                if (isset($context["ssl"])) {
                    $this->negotiateCrypto();
                }

                $this->httpDriver = $driverFactory->selectDriver(
                    $this,
                    $this->errorHandler,
                    $this->logger,
                    $this->options
                );

                $requestParser = $this->httpDriver->setup(
                    $this,
                    \Closure::fromCallable([$this, 'onMessage']),
                    \Closure::fromCallable([$this, 'write'])
                );

                $requestParser->current(); // Advance parser to first yield for data.

                while (!$this->isExported && null !== $chunk = $this->socket->read()) {
                    $future = $requestParser->send($chunk); // Parser yields a Future or null.
                    if ($future instanceof Future) {
                        $future->await();
                        $requestParser->send(null); // Signal the parser that the yielded future has completed.
                    }
                }
            } catch (\Throwable $exception) {
                \assert($this->logger->debug(\sprintf(
                        "Exception while handling client %s: %s",
                        $this->socket->getRemoteAddress(),
                        $exception->getMessage()
                    )) || true
                );

                $this->close();
            }
        });
    }

    /**
     * Called by start() after the client connects if encryption is enabled.
     */
    private function negotiateCrypto(): void
    {
        $this->timeoutCache->update(
            $this->id,
            \time() + $this->options->getTlsSetupTimeout()
        );

        $this->socket->setupTls(new TimeoutCancellation($this->options->getTlsSetupTimeout()));

        $this->tlsInfo = $this->socket->getTlsInfo();
        \assert($this->tlsInfo !== null);

        \assert($this->logger->debug(\sprintf(
                "TLS negotiated with %s (%s with %s, application protocol: %s)",
                $this->socket->getRemoteAddress()->toString(),
                $this->tlsInfo->getVersion(),
                $this->tlsInfo->getCipherName(),
                $this->tlsInfo->getApplicationLayerProtocol() ?? "none"
            )) || true
        );
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
        if (!isset($this->httpDriver)) {
            return 0;
        }

        return $this->httpDriver->getPendingRequestCount();
    }

    /** @inheritdoc */
    public function isWaitingOnResponse(): bool
    {
        return isset($this->httpDriver) && $this->pendingHandlers > $this->httpDriver->getPendingRequestCount();
    }

    /** @inheritdoc */
    public function getId(): int
    {
        return $this->id;
    }

    /** @inheritdoc */
    public function getRemoteAddress(): SocketAddress
    {
        return $this->socket->getRemoteAddress();
    }

    /** @inheritdoc */
    public function getLocalAddress(): SocketAddress
    {
        return $this->socket->getLocalAddress();
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

        $this->socket->close();

        \assert((function (): bool {
            if (($this->socket->getLocalAddress()->getHost()[0] ?? "") !== "/") { // no unix domain socket
                $this->logger->debug("Close {$this->socket->getRemoteAddress()} #{$this->id}");
            } else {
                $this->logger->debug("Close connection on {$this->socket->getLocalAddress()} #{$this->id}");
            }
            return true;
        })());

        foreach ($onClose as $callback) {
            EventLoop::defer(fn () => $callback($this));
        }
    }

    /** @inheritdoc */
    public function onClose(callable $callback): void
    {
        if ($this->onClose === null) {
            EventLoop::defer(fn () => $callback($this));
            return;
        }

        $this->onClose[] = $callback;
    }

    /** @inheritdoc */
    public function stop(float $timeout): void
    {
        if (!isset($this->httpDriver)) {
            $this->close();
            return;
        }

        try {
            async(fn () => $this->httpDriver->stop())->await(new TimeoutCancellation($timeout));
        } finally {
            $this->close();
        }
    }

    private function clear(): void
    {
        unset($this->httpDriver, $this->requestParser);
        $this->timeoutCache->clear($this->id);
    }

    /**
     * Adds the given data to the buffer of data to be written to the client socket. Returns a promise that resolves
     * once the client write buffer has emptied.
     *
     * @param string $data The data to write.
     * @param bool $close If true, close the client after the given chunk of data has been written.
     *
     * @return Future
     */
    private function write(string $data, bool $close = false): Future
    {
        if ($this->status & self::CLOSED_WR) {
            return Future::error(new ClientException($this, "Client socket closed"));
        }

        if ($close) {
            $this->status |= self::CLOSED_WR;
            return $this->socket->end($data);
        }

        return $this->socket->write($data);
    }

    /**
     * Invoked by the HTTP parser when a request is parsed.
     *
     * @param Request $request
     * @param string $buffer Remaining buffer in the parser.
     *
     * @return Future
     */
    private function onMessage(Request $request, string $buffer = ''): Future
    {
        \assert($this->logger->debug(\sprintf(
                "%s %s HTTP/%s @ %s",
                $request->getMethod(),
                $request->getUri(),
                $request->getProtocolVersion(),
                $this->socket->getRemoteAddress()->toString()
            )) || true);

        return async(fn () => $this->respond($request, $buffer));
    }

    /**
     * Respond to a parsed request.
     *
     * @param Request $request
     * @param string $buffer
     */
    private function respond(Request $request, string $buffer): void
    {
        $clientRequest = $request;
        $request = clone $request;

        $this->pendingHandlers++;
        $this->pendingResponses++;

        try {
            $method = $request->getMethod();

            if (!\in_array($method, $this->options->getAllowedMethods(), true)) {
                $response = $this->makeMethodErrorResponse(
                    \in_array($method, HttpDriver::KNOWN_METHODS, true)
                        ? Status::METHOD_NOT_ALLOWED
                        : Status::NOT_IMPLEMENTED
                );
            } elseif ($method === "OPTIONS" && $request->getUri()->getPath() === "") {
                $response = $this->makeOptionsResponse();
            } else {
                $response = $this->requestHandler->handleRequest($request);
            }
        } catch (ClientException) {
            $this->stop(self::SHUTDOWN_TIMEOUT_ON_ERROR);
            $this->close();
            return;
        } catch (\Throwable $exception) {
            $errorType = \get_class($exception);
            $this->logger->error(
                "Unexpected {$errorType} thrown from RequestHandler::handleRequest(), falling back to error handler.",
                $this->createLogContext($exception, $request)
            );

            $response = $this->makeExceptionResponse($request);
        } finally {
            $this->pendingHandlers--;
        }

        if ($this->status & self::CLOSED_WR) {
            return; // Client closed before response could be sent.
        }

        if ($response->isUpgraded()) {
            $this->isExported = true;
        }

        $this->httpDriver->write($clientRequest, $response);

        $this->pendingResponses--;

        if ($this->isExported) {
            $this->export($response->getUpgradeHandler(), $request, $response, $buffer);
        }
    }

    private function makeMethodErrorResponse(int $status): Response
    {
        $response = $this->errorHandler->handleError($status);
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
     */
    private function makeExceptionResponse(Request $request): Response
    {
        $status = Status::INTERNAL_SERVER_ERROR;

        try {
            return $this->errorHandler->handleError($status, null, $request);
        } catch (\Throwable $exception) {
            // If the error handler throws, fallback to returning the default error page.
            $errorType = \get_class($exception);
            $this->logger->error(
                "Unexpected {$errorType} thrown from ErrorHandler::handleError(), falling back to default error handler.",
                $this->createLogContext($exception, $request)
            );

            // The default error handler will never throw, otherwise there's a bug
            return self::$defaultErrorHandler->handleError($status, null, $request);
        }
    }

    /**
     * Invokes the export function on Response with the socket upgraded from the HTTP server.
     *
     * @param callable $upgrade
     * @param Request $request
     * @param Response $response
     * @param string $buffer Remaining buffer read from the socket.
     */
    private function export(callable $upgrade, Request $request, Response $response, string $buffer): void
    {
        if ($this->status & self::CLOSED_RDWR) {
            return;
        }

        $this->clear();

        \assert($this->logger->debug("Upgrade {$this->socket->getRemoteAddress()} #{$this->id}") || true);

        $socket = new UpgradedSocket($this, $this->socket, $buffer);

        try {
            $upgrade($socket, $request, $response);
        } catch (\Throwable $exception) {
            $errorType = \get_class($exception);
            $this->logger->error(
                "Unexpected {$errorType} thrown during socket upgrade, closing connection.",
                $this->createLogContext($exception, $request)
            );

            $this->close();
        }
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

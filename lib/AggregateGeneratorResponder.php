<?php

namespace Aerys;

use Amp\Future;
use Amp\Promise;
use Amp\Failure;
use Amp\Success;
use Amp\Promisor;
use Amp\YieldCommands as SystemYieldCommands;
use Aerys\YieldCommands as HttpYieldCommands;

class AggregateGeneratorResponder implements Responder {
    private $aggregateHandler;
    private $nextHandlerIndex;
    private $request;
    private $generator;
    private $promisor;
    private $environment;
    private $isOutputStarted;
    private $isSocketGone;
    private $shouldNotifyUserAbort;
    private $isChunking;
    private $isFinalWrite;
    private $buffer = '';
    private $status = HTTP_STATUS["OK"];
    private $reason;
    private $headers = [];

    public function __construct(
        AggregateRequestHandler $aggregateHandler,
        $nextHandlerIndex,
        array $request,
        \Generator $gen
    ) {
        $this->aggregateHandler = $aggregateHandler;
        $this->nextHandlerIndex = $nextHandlerIndex;
        $this->request = $request;
        $this->generator = $gen;
    }

    /**
     * Prepare the Responder for client output
     *
     * @param ResponderEnvironment $env
     */
    public function prepare(ResponderEnvironment $env) {
        $this->environment = $env;
        $this->promisor = new Future;
    }

    /**
     * Assume control of the client socket and output the prepared response
     *
     * @return void
     */
    public function assumeSocketControl() {
        $this->write();
    }

    /**
     * Write the prepared Response
     */
    public function write() {
        if (isset($this->buffer[0])) {
            $this->doWrite();
        } else {
            $this->advanceGenerator($this->generator, $this->promisor);
        }
    }

    private function doWrite() {
        $env = $this->environment;
        $bytesWritten = @fwrite($env->socket, $this->buffer);

        if ($bytesWritten === strlen($this->buffer)) {
            $this->onBufferDrain();
        } elseif ($bytesWritten === false) {
            $this->buffer = '';
            $this->isSocketGone = $this->shouldNotifyUserAbort = true;
            $env->reactor->disable($env->writeWatcher);

            // Always notify the HTTP server immediately in the event of a
            // client disconnect even though the application may choose to
            // continue processing.
            $env->server->resumeSocketControl($env->requestId, $mustClose = true);
        } else {
            $this->buffer = substr($this->buffer, $bytesWritten);
            $env->reactor->enable($env->writeWatcher);
        }
    }

    private function onBufferDrain() {
        $this->buffer = '';
        $env = $this->environment;
        $env->reactor->disable($env->writeWatcher);
        if ($this->isFinalWrite) {
            $env->server->resumeSocketControl($env->requestId, $env->mustClose);
        }
    }

    private function resolveGenerator(\Generator $gen) {
        $prom = new Future;
        $this->advanceGenerator($gen, $prom);

        return $prom;
    }

    private function advanceGenerator(\Generator $gen, Promisor $prom, $previousResult = null) {
        try {
            if ($gen->valid()) {
                $key = $gen->key();
                $current = $gen->current();
                $promiseStruct = $this->promisifyYield($key, $current);

                // An empty result means our aggregate handler short-circuited this
                // responder and we're finished.
                if (empty($promiseStruct)) {
                    return;
                }

                $this->environment->reactor->immediately(function() use ($gen, $prom, $promiseStruct) {
                    list($promise, $noWait) = $promiseStruct;
                    if ($noWait) {
                        $this->sendToGenerator($gen, $prom, $error = null, $result = null);
                    } else {
                        $promise->when(function($error, $result) use ($gen, $prom) {
                            $this->sendToGenerator($gen, $prom, $error, $result);
                        });
                    }
                });
            } elseif ($prom !== $this->promisor) {
                $prom->succeed($previousResult);
            } elseif ($this->isOutputStarted) {
                $this->finalizeWrite();
            } else {
                $this->startOutput();
                $this->finalizeWrite();
            }
        } catch (\Exception $error) {
            if ($prom === $this->promisor) {
                $this->onTopLevelError($error);
            } else {
                $prom->fail($error);
            }
        }
    }

    private function promisifyYield($key, $current) {
        $noWait = false;

        if ($this->isSocketGone && $this->shouldNotifyUserAbort) {
            $this->shouldNotifyUserAbort = false;
            $promise = new Failure(new ClientGoneException);
            goto return_struct;
        } elseif ($key === (string) $key) {
            goto explicit_key;
        } else {
            goto implicit_key;
        }

        explicit_key: {
            $key = strtolower($key);
            if ($key[0] === SystemYieldCommands::NOWAIT_PREFIX) {
                $noWait = true;
                $key = substr($key, 1);
            }

            switch ($key) {
                case HttpYieldCommands::STATUS:
                    goto status;
                case HttpYieldCommands::REASON:
                    goto reason;
                case HttpYieldCommands::HEADER:
                    goto header;
                case HttpYieldCommands::BODY:
                    goto body;
                case SystemYieldCommands::BIND:
                    goto bind;
                case SystemYieldCommands::IMMEDIATELY:
                    goto immediately;
                case SystemYieldCommands::ONCE:
                    // fallthrough
                case SystemYieldCommands::REPEAT:
                    goto schedule;
                case SystemYieldCommands::ON_READABLE:
                    $ioWatchMethod = 'onReadable';
                    goto stream_io_watcher;
                case SystemYieldCommands::ON_WRITABLE:
                    $ioWatchMethod = 'onWritable';
                    goto stream_io_watcher;
                case SystemYieldCommands::ENABLE:
                    // fallthrough
                case SystemYieldCommands::DISABLE:
                    // fallthrough
                case SystemYieldCommands::CANCEL:
                    goto watcher_control;
                case SystemYieldCommands::PAUSE:
                    goto pause;
                case SystemYieldCommands::ALL:
                    // fallthrough
                case SystemYieldCommands::ANY:
                    // fallthrough
                case SystemYieldCommands::SOME:
                    goto combinator;
                case SystemYieldCommands::NOWAIT:
                    goto implicit_key;
                default:
                    if ($noWait) {
                        goto implicit_key;
                    } else {
                        $promise = new Failure(new \DomainException(
                            sprintf('Unknown or invalid yield command key: "%s"', $key)
                        ));
                        goto return_struct;
                    }
            }
        }

        implicit_key: {
            if ($current instanceof Promise) {
                $promise = $current;
            } elseif ($current instanceof \Generator) {
                $promise = $this->resolveGenerator($current);
            } elseif (is_array($current)) {
                // An array without an explicit key is assumed to be an "all" combinator
                $key = SystemYieldCommands::ALL;
                goto combinator;
            } else {
                $promise = new Success($current);
            }

            goto return_struct;
        }

        bind: {
            if (is_callable($current)) {
                $promise = new Success(function() use ($current) {
                    $result = call_user_func_array($current, func_get_args());
                    return $result instanceof \Generator
                        ? $this->resolveGenerator($result)
                        : $result;
                });
            } else {
                $promise = new Failure(new \DomainException(
                    sprintf('"bind" yield command requires callable; %s provided', gettype($current))
                ));
            }

            goto return_struct;
        }

        immediately: {
            if (is_callable($current)) {
                $func = $this->wrapWatcherCallback($current);
                $watcherId = $this->environment->reactor->immediately($func);
                $promise = new Success($watcherId);
            } else {
                $promise = new Failure(new \DomainException(
                    sprintf(
                        '%s yield command requires callable; %s provided',
                        $key,
                        gettype($current)
                    )
                ));
            }

            goto return_struct;
        }

        schedule: {
            if ($current && isset($current[0], $current[1]) && is_array($current)) {
                list($func, $msDelay) = $current;
                $func = $this->wrapWatcherCallback($func);
                $watcherId = $this->environment->reactor->{$key}($func, $msDelay);
                $promise = new Success($watcherId);
            } else {
                $promise = new Failure(new \DomainException(
                    sprintf(
                        '%s yield command requires [callable $func, int $msDelay]; %s provided',
                        $key,
                        gettype($current)
                    )
                ));
            }

            goto return_struct;
        }

        stream_io_watcher: {
            if (is_array($current) && isset($current[0], $current[1], $current[2]) && is_callable($current[1])) {
                list($stream, $func, $enableNow) = $current;
                $func = $this->wrapWatcherCallback($func);
                $watcherId = $this->reactor->{$ioWatchMethod}($stream, $func, $enableNow);
                $promise = new Success($watcherId);
            } else {
                $promise = new Failure(new \DomainException(
                    sprintf(
                        '%s yield command requires [resource $stream, callable $func, bool $enableNow]; %s provided',
                        $key,
                        gettype($current)
                    )
                ));
            }

            goto return_struct;
        }

        watcher_control: {
            $this->environment->reactor->{$key}($current);
            $promise = new Success;

            goto return_struct;
        }

        pause: {
            $promise = new Future;
            $this->environment->reactor->once(function() use ($promise) {
                $promise->succeed();
            }, (int) $current);

            goto return_struct;
        }

        combinator: {
            foreach ($current as $index => $element) {
                if ($element instanceof \Generator) {
                    $current[$index] = $this->resolveGenerator($element);
                }
            }

            $combinator = "\\Amp\\{$key}";
            $promise = $combinator($current);

            goto return_struct;
        }

        status: {
            $status = (int) $current;
            if ($this->isOutputStarted) {
                $promise = new Failure(new \LogicException(
                    'Cannot assign status code: output already started'
                ));
            } elseif ($status < 100 || $status > 599) {
                $promise = new Failure(new \DomainException(
                    'Cannot assign status code: integer in the set [100,599] required'
                ));
            } else {
                $this->status = $status;
                $promise = new Success($status);
            }

            goto return_struct;
        }

        reason: {
            if ($this->isOutputStarted) {
                $promise = new Failure(new \LogicException(
                    'Cannot assign reason phrase: output already started'
                ));
            } else {
                $this->reason = $reason = (string) $current;
                $promise = new Success($reason);
            }

            goto return_struct;
        }

        header: {
            if ($this->isOutputStarted) {
                $promise = new Failure(new \LogicException(
                    'Cannot assign header: output already started'
                ));
            } elseif (is_array($current)) {
                $this->headers += $current;
                $promise = new Success($current);
            } elseif (is_string($current)) {
                $this->headers[] = (string) $current;
                $promise = new Success($current);
            } else {
                $promise = new Failure(new \DomainException(
                    sprintf(
                        'header yield command expects a string or array of strings; %s yielded',
                        gettype($current)
                    )
                ));
            }

            goto return_struct;
        }

        body: {
            // If our $beforeOutput callback short-circuits the responder we're finished.
            // Return null so advanceGenerator() knows not to proceed any further.
            if (!($this->isOutputStarted || $this->startOutput())) {
                return null;
            }

            $current = (string) $current;
            $promise = new Success($current);

            if ($this->isSocketGone) {
                // If we've gotten this far the application has already
                // caught the ClientGoneException and chosen to continue
                // processing. Indicate success to the generator but do
                // not buffer any further data for writing.

                goto return_struct;
            }

            if ("" !== $current) {
                $chunk = $this->isChunking ? dechex(strlen($current)) . "\r\n{$current}\r\n" : $current;
                $this->buffer .= $chunk;
            }

            $this->doWrite();

            goto return_struct;
        }

        return_struct: {
            return [$promise, $noWait];
        }
    }

    private function wrapWatcherCallback(callable $func) {
        return function($reactor, $watcherId, $stream = null) use ($func) {
            try {
                $result = $func($reactor, $watcherId, $stream);
                if ($result instanceof \Generator) {
                    $this->resolveGenerator($result);
                }
            } catch (\Exception $e) {
                $this->environment->server->log($e);
                // @TODO Add information about the request that resulted in this watcher
                // registration to make debugging easier
            }
        };
    }

    private function sendToGenerator(\Generator $gen, Promisor $prom, \Exception $error = null, $result = null) {
        try {
            if ($this->shouldNotifyUserAbort) {
                $this->shouldNotifyUserAbort = false;
                $gen->throw(new ClientGoneException);
            } elseif ($error) {
                $gen->throw($error);
            } else {
                $gen->send($result);
            }
            $this->advanceGenerator($gen, $prom, $result);
        } catch (ClientGoneException $error) {
            // There's nothing else to do. The application didn't catch
            // the user abort and the server has already been notified
            // that the client disconnected. We're finished.
            return;
        } catch (\Exception $error) {
            if ($prom === $this->promisor) {
                $this->onTopLevelError($error);
            } else {
                $prom->fail($error);
            }
        }
    }

    private function startOutput() {
        if ($this->status == HTTP_STATUS["NOT_FOUND"]) {
            $responder = $this->aggregateHandler->__invoke($this->request, $this->nextHandlerIndex);
            $responder->prepare($this->environment);
            $responder->assumeSocketControl();
            return false;
        }

        $this->isOutputStarted = true;

        $env = $this->environment;
        $request = $env->request;
        $protocol = $request['SERVER_PROTOCOL'];
        $status = $this->status;
        $reason = isset($this->reason)
            ? $this->reason
            : (@HTTP_REASON[$status] ?: '');

        if ($status < 200) {
            $env->mustClose = false;
            $this->buffer = "HTTP/{$protocol} {$status} {$reason}\r\n\r\n";
            $this->isFinalWrite = true;
            return true;
        }

        $headers = $this->headers ? implode("\r\n", $this->headers) : '';
        $headers = removeHeader($headers, 'Content-Length');

        if ($env->mustClose) {
            $headers = setHeader($headers, 'Connection', 'close');
            $transferEncoding = 'identity';
        } elseif (headerMatches($headers, 'Connection', 'close')) {
            $env->mustClose = true;
            $transferEncoding = 'identity';
        } elseif ($protocol >= 1.1) {
            // Append Connection header, don't set. There are scenarios where
            // multiple Connection headers are required (e.g. websocket handshakes).
            $headers = addHeaderLine($headers, "Connection: keep-alive");
            $headers = setHeader($headers, 'Keep-Alive', $env->keepAlive);
            $this->isChunking = true;
            $transferEncoding = 'chunked';
        } else {
            $env->mustClose = true;
            $headers = setHeader($headers, 'Connection', 'close');
            $transferEncoding = 'identity';
        }

        $headers = setHeader($headers, 'Transfer-Encoding', $transferEncoding);

        // @TODO Apply Content-Encoding: gzip if the originating HTTP request supports it

        if ($status >= 200 && ($status < 300 || $status >= 400)) {
            $contentType = hasHeader($headers, 'Content-Type')
                ? getHeader($headers, 'Content-Type')
                : $env->defaultContentType;

            if (stripos($contentType, 'text/') === 0 && stripos($contentType, 'charset=') === false) {
                $contentType .= "; charset={$env->defaultTextCharset}";
            }

            $headers = setHeader($headers, 'Content-Type', $contentType);
        } else {
            $headers = removeHeader($headers, 'Content-Type');
        }

        $headers = setHeader($headers, 'Date', $env->httpDate);

        if ($env->serverToken) {
            $headers = setHeader($headers, 'Server', $env->serverToken);
        }

        if ($request['REQUEST_METHOD'] === 'HEAD') {
            $this->isFinalWrite = true;
        }

        $this->buffer = "HTTP/{$protocol} {$status} {$reason}\r\n{$headers}\r\n\r\n";

        return true;
    }

    private function finalizeWrite() {
        if ($this->isSocketGone) {
            return;
        }

        if ($this->isFinalWrite || !$this->isChunking) {
            $env = $this->environment;
            $env->server->resumeSocketControl($env->requestId, $env->mustClose);
            return;
        }

        $this->isFinalWrite = true;
        $this->buffer .= "0\r\n\r\n";
        $this->doWrite();
    }

    private function onTopLevelError(\Exception $error) {
        $env = $this->environment;
        $env->mustClose = true;
        $this->isFinalWrite = true;

        if (!$this->isOutputStarted) {
            // If output hasn't started yet we send a 500 response
            $responder = $this->aggregateHandler->makeErrorResponder($error);
            $responder->prepare($env);
            $responder->assumeSocketControl();
        } elseif ($this->aggregateHandler->getDebugFlag()) {
            // If output has started and we're running in debug mode dump the error to the buffer
            // and don't worry about logging it
            $msg = "<pre>{$error}</pre>";
            $chunk = $this->isChunking ? dechex(strlen($msg)) . "\r\n{$msg}\r\n0\r\n\r\n" : $msg;
            $this->buffer .= $chunk;
            $this->doWrite();
        } elseif ($this->isChunking) {
            $this->buffer .= "0\r\n\r\n";
            $this->doWrite();
        } else {
            // If debug mode isn't enabled then log the error and end response output when the
            // current write buffer is flushed
            $env->server->log($error);
        }
    }
}

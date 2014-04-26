<?php

namespace Aerys\Blockable;

use Amp\Thread,
    Aerys\Status,
    Aerys\Reason,
    Aerys\Response,
    Aerys\TargetPipeException;

class ThreadRequestTask extends \Threaded {
    private $request;
    private $socket;
    private $settings;
    private $mustClose;
    private $dateHeader;
    private $serverHeader;
    private $keepAliveHeader;
    private $defaultContentType;
    private $defaultTextCharset;
    private $autoReasonPhrase;
    private $debug;
    private $isStreaming;
    private $isChunking;

    public function __construct($request, $socket, $subject) {
        $this->request = $request;
        $this->socket = $socket;
        $this->mustClose = $subject->mustClose;
        $this->dateHeader = $subject->dateHeader;
        $this->serverHeader = $subject->serverHeader;
        $this->keepAliveHeader = $subject->keepAliveHeader;
        $this->defaultContentType = $subject->defaultContentType;
        $this->defaultTextCharset = $subject->defaultTextCharset;
        $this->autoReasonPhrase = $subject->autoReasonPhrase;
        $this->debug = $subject->debug;
    }

    public function run() {
        $request =& $this->request;
        $request['ASGI_NON_BLOCKING'] = FALSE;
        /*
        // @TODO
        list($injector, $threadLocal) = $this->worker->getDomainShare('__aerysBlockables');
        $request['AERYS_THREAD_LOCAL'] = $threadLocal;
        $executable = $injector->getExecutable($request['AERYS_THREAD_ROUTE']);
        */
        $executable = $request['AERYS_THREAD_ROUTE'];
        unset($request['AERYS_THREAD_ROUTE']);
        $response = $this->tryResponder($executable, $request);

        $proto = $request['SERVER_PROTOCOL'];
        list($startLineAndHeaders, $body, $mustClose) = $this->normalizeResponse($response, $proto);

        // IMPORTANT: This MUST happen AFTER response normalization or headers
        // won't be correct when responding to HEAD requests.
        if ($request['REQUEST_METHOD'] === 'HEAD') {
            $body = '';
            $this->isStreaming = FALSE;
        }

        $taskResult = $this->isStreaming
            ? $this->streamResponse($startLineAndHeaders, $body, $mustClose)
            : $this->writeResponse($startLineAndHeaders, $body, $mustClose);

        $this->worker->registerResult(Thread::SUCCESS, $taskResult);
    }

    private function tryResponder(callable $executable, array $request) {
        try {
            ob_start();
            $response = call_user_func($executable, $request);
            $output = ob_get_clean();

            if ($output != '') {
                throw new UnexpectedOutputException($output);
            } elseif ($response instanceof Response) {
                $isStreaming = is_callable($response->getBody());
            } elseif (is_string($response)) {
                $response = (new Response)->setBody($response);
                $isStreaming = FALSE;
            } elseif (is_callable($response)) {
                $response = (new Response)->setBody($response);
                $isStreaming = TRUE;
            } else {
                throw new \DomainException(
                    sprintf("Response, string or callable required; %s returned", gettype($response))
                );
            }

            $this->isStreaming = $isStreaming;
            $this->isChunking = ($isStreaming && $request['SERVER_PROTOCOL'] >= 1.1);

            return $response;

        } catch (\Exception $e) {
            return $this->makeExceptionResponse($e);
        }
    }

    private function makeExceptionResponse(\Exception $e) {
        $this->isStreaming = $this->isChunking = FALSE;

        $msg = $this->debug ? "<pre>{$e}</pre>" : '<p>Something went terribly wrong</p>';
        $body = "<html><body><h1>500 Internal Server Error</h1>{$msg}</body></html>";
        $response = new Response;
        $response->setStatus(Status::INTERNAL_SERVER_ERROR);
        $response->setReason(Reason::HTTP_500);
        $response->setBody($body);

        // @TODO Log the error here. For now we'll just send it to STDERR:
        @fwrite(STDERR, $e);

        return $response;
    }

    private function normalizeResponse(Response $response, $proto) {
        list($status, $reason, $body) = $response->toList();

        // --- Normalize Connection and Keep-Alive -------------------------------------------------

        $mustClose = $this->mustClose ?: $response->hasHeaderMatch('Connection', 'close');
        $mustClose = $mustClose ?: ($this->isStreaming && !$this->isChunking);

        if ($mustClose) {
            $response->setHeader('Connection', 'close');
            $response->removeHeader('Keep-Alive');
        } else {
            $response->setHeader('Connection', 'keep-alive');
            $response->setHeader('Keep-Alive', $this->keepAliveHeader);
        }

        // --- Normalize Content-Length and Transfer-Encoding --------------------------------------

        $response->setHeader('Transfer-Encoding', $this->isChunking ? 'chunked' : 'identity');
        if ($this->isStreaming) {
            $response->removeHeader('Content-Length');
        } else {
            $response->setHeader('Content-Length', strlen($body));
        }

        // --- Normalize Content-Type --------------------------------------------------------------

        $contentType = $response->getHeaderSafe('Content-Type') ?: $this->defaultContentType;
        if (stripos($contentType, 'text/') === 0 && stripos($contentType, 'charset=') === FALSE) {
            $contentType .= "; charset={$this->defaultTextCharset}";
        }

        $response->setHeader('Content-Type', $contentType);

        // --- Normalize other miscellaneous stuff -------------------------------------------------

        $response->setHeader('Date', $this->dateHeader);
        if ($this->serverHeader) {
            $response->setHeader('Server', $this->serverHeader);
        }

        if ($this->autoReasonPhrase && $reason == '') {
            $reasonConstant = "Aerys\Reason::HTTP_{$status}";
            $reason = defined($reasonConstant) ? constant($reasonConstant) : '';
        }

        // --- Finally, return the normalized results ----------------------------------------------

        $finalReason = $reason == '' ? '' : " {$reason}";
        $finalHeaders = $response->getRawHeaders();
        $startLineAndHeaders = "HTTP/{$proto} {$status}{$finalReason}{$finalHeaders}\r\n\r\n";

        return [$startLineAndHeaders, $body, $mustClose];
    }

    private function streamResponse($startLineAndHeaders, $body, $mustClose) {
        try {
            $this->writeToSocket($startLineAndHeaders);
            $obCallback = $this->isChunking ? 'chunkOutput' : 'writeToSocket';
            ob_start([$this, $obCallback], $chunkSize = 8192);
            call_user_func($body);
            ob_end_flush();
            return $mustClose;
        } catch (TargetPipeException $e) {
            // The socket is gone; we're finished here.
            return TRUE;
        } catch (\Exception $e) {
            ob_end_clean();
            // @TODO Log the error here. For now we'll just send it to STDERR:
            @fwrite(STDERR, $e);
            // ALWAYS close the connection after an application error. Trying to recover from this
            // on the same connection will result in a malformed HTTP response message. We have no
            // but to use a connection close to signify the end of the HTTP response message.
            return TRUE;
        }
    }

    /**
     * Client sockets are always non-blocking. We don't want to change this setting because
     * it could screw things up for data reads on the socket in the main thread. As a result,
     * we have to manually ensure all data is written instead of using a single blocking fwrite().
     */
    private function writeToSocket($data) {
        $bytesWritten = 0;
        $dataLen = strlen($data);

        while (TRUE) {
            $lastWriteSize = @fwrite($this->socket, $data);
            $bytesWritten += $lastWriteSize;

            if ($bytesWritten >= $dataLen) {
                break;
            } elseif ($lastWriteSize) {
                $data = substr($data, $lastWriteSize);
            } elseif (!is_resource($this->socket)) {
                throw new TargetPipeException;
            }
        }
        
        return '';
    }

    private function chunkOutput($buffer, $phase) {
        $buffer = dechex(strlen($buffer)) . "\r\n{$buffer}\r\n";
        if ($phase & PHP_OUTPUT_HANDLER_FINAL) {
            $buffer .= "0\r\n\r\n";
        }

        $this->writeToSocket($buffer);

        // Always return an empty string because we don't want anything going to STDOUT
        return '';
    }

    private function writeResponse($startLineAndHeaders, $body, $mustClose) {
        try {
            $rawResponse = $startLineAndHeaders . $body;
            $this->writeToSocket($rawResponse);
            return $mustClose;
        } catch (TargetPipeException $e) {
            // The socket is gone; we're finished here.
            return $mustClose = TRUE;
        }
    }
}

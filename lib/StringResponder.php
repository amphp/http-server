<?php

namespace Aerys;

use Amp\Future;
use Amp\Failure;
use Amp\Success;

class StringResponder implements Responder {
    private $struct;
    private $buffer = '';
    private $bufferSize = 0;
    private $isWatcherEnabled;
    private $promisor;

    /**
     * Prepare the Responder
     *
     * @param \Aerys\ResponderStruct $struct
     */
    public function prepare(ResponderStruct $struct) {
        $this->struct = $struct;

        $request = $struct->request;
        $protocol = $request['SERVER_PROTOCOL'];

        $status = 200;
        $reason = $header = $body = '';
        extract($struct->response);
        if (is_array($header)) {
            $header = implode("\r\n", $header);
        }

        $reason = ($reason == '') ? $reason : " {$reason}"; // leading space is important!

        // @TODO Validate message if ($struct->debug == true) {...}

        if ($status < 200) {
            $struct->mustClose = false;
            $this->buffer = "HTTP/{$protocol} {$status}{$reason}\r\n\r\n";
            $this->bufferSize = strlen($this->buffer);
            return;
        }

        $header = setHeader($header, 'Content-Length', strlen($body));

        if ($struct->mustClose || $protocol < 1.1 || headerMatches($header, 'Connection', 'close')) {
            $struct->mustClose = true;
            $header = setHeader($header, 'Connection', 'close');
        } else {
            // Append Connection header, don't set. There are scenarios where
            // multiple Connection headers are required (e.g. websockets).
            $header = addHeaderLine($header, "Connection: keep-alive");
            $header = setHeader($header, 'Keep-Alive', $struct->keepAlive);
        }

        $contentType = hasHeader($header, 'Content-Type')
            ? getHeader($header, 'Content-Type')
            : $struct->defaultContentType;

        if (stripos($contentType, 'text/') === 0 && stripos($contentType, 'charset=') === false) {
            $contentType .= "; charset={$struct->defaultTextCharset}";
        }

        $header = setHeader($header, 'Content-Type', $contentType);
        $header = setHeader($header, 'Date', $struct->httpDate);

        if ($struct->serverToken) {
            $header = setHeader($header, 'Server', $struct->serverToken);
        }

        // IMPORTANT: This MUST happen AFTER other headers are normalized or headers
        // won't be correct when responding to HEAD requests. Don't move this above
        // the header normalization lines!
        if ($request['REQUEST_METHOD'] === 'HEAD') {
            $body = '';
        }

        $this->buffer = "HTTP/{$protocol} {$status}{$reason}\r\n{$header}\r\n\r\n{$body}";
        $this->bufferSize = strlen($this->buffer);
    }

    /**
     * Write the prepared response
     *
     * @return \Amp\Promise A Promise that resolves upon write completion
     */
    public function write() {
        $bytesWritten = @fwrite($this->struct->socket, $this->buffer);

        if ($bytesWritten === $this->bufferSize) {
            goto write_complete;
        } elseif ($bytesWritten !== false) {
            goto write_incomplete;
        } else {
            goto write_error;
        }

        write_complete: {
            if ($this->isWatcherEnabled) {
                $this->struct->reactor->disable($this->struct->writeWatcher);
                $this->isWatcherEnabled = false;
            }

            return $this->promisor
                ? $this->promisor->succeed($this->struct->mustClose)
                : new Success($this->struct->mustClose);
        }

        write_incomplete: {
            $this->bufferSize -= $bytesWritten;
            $this->buffer = substr($this->buffer, $bytesWritten);

            if (!$this->isWatcherEnabled) {
                $this->isWatcherEnabled = true;
                $this->struct->reactor->enable($this->struct->writeWatcher);
            }

            return $this->promisor ?: ($this->promisor = new Future($this->struct->reactor));
        }

        write_error: {
            if ($this->isWatcherEnabled) {
                $this->isWatcherEnabled = false;
                $this->struct->reactor->disable($this->struct->writeWatcher);
            }

            $error = new ClientGoneException(
                'Write failed: destination stream went away'
            );

            return $this->promisor ? $this->promisor->fail($error) : new Failure($error);
        }
    }
}

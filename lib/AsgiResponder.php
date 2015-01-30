<?php

namespace Aerys;

class AsgiResponder implements Responder {
    private $asgiResponse;
    private $environment;
    private $buffer = '';

    /**
     * We specifically avoid typehinting the $asgiResponse parameter as an array here to allow
     * for ArrayAccess instances.
     *
     * @param array|ArrayAccess $asgiResponse
     */
    public function __construct($asgiResponse) {
        $this->asgiResponse = $asgiResponse;
    }

    /**
     * Prepare the Responder for client output
     *
     * @param ResponderEnvironment $env
     * @return void
     */
    public function prepare(ResponderEnvironment $env) {
        $this->environment = $env;

        $request = $env->request;
        $protocol = $request['SERVER_PROTOCOL'];

        $asgiResponse = $this->asgiResponse;
        $status = isset($asgiResponse['status']) ? $asgiResponse['status'] : HTTP_STATUS["OK"];
        $reason = isset($asgiResponse['reason'])
            ? $asgiResponse['reason']
            : (@HTTP_REASON[$status] ?: '');
        $header = isset($asgiResponse['header']) ? $asgiResponse['header'] : '';
        $body = isset($asgiResponse['body']) ? $asgiResponse['body'] : '';

        if (is_array($header)) {
            $header = implode("\r\n", $header);
        }

        if ($status < 200) {
            $env->mustClose = false;
            $this->buffer = "HTTP/{$protocol} {$status} {$reason}\r\n\r\n";
            return;
        }

        if ($env->mustClose || $protocol < 1.1 || headerMatches($header, 'Connection', 'close')) {
            $env->mustClose = true;
            $header = setHeader($header, 'Connection', 'close');
        } else {
            // Append Connection header, don't set. There are scenarios where
            // multiple Connection headers are required (e.g. websockets).
            $header = addHeaderLine($header, "Connection: keep-alive");
            $header = setHeader($header, 'Keep-Alive', $env->keepAlive);
        }

        // @TODO Apply Content-Encoding: gzip if the originating HTTP request supports it

        if ($status >= 200 && ($status < 300 || $status >= 400)) {
            $contentType = hasHeader($header, 'Content-Type')
                ? getHeader($header, 'Content-Type')
                : $env->defaultContentType;

            if (stripos($contentType, 'text/') === 0 && stripos($contentType, 'charset=') === false) {
                $contentType .= "; charset={$env->defaultTextCharset}";
            }

            $header = setHeader($header, 'Content-Type', $contentType);
            $header = setHeader($header, 'Content-Length', strlen($body));
        } else {
            $header = removeHeader($header, 'Content-Type');
            $header = removeHeader($header, 'Content-Length');
        }

        $header = setHeader($header, 'Date', $env->httpDate);

        if ($env->serverToken) {
            $header = setHeader($header, 'Server', $env->serverToken);
        }

        // IMPORTANT: This MUST happen AFTER other headers are normalized or headers
        // won't be correct when responding to HEAD requests. Don't move this above
        // the header normalization lines!
        if ($request['REQUEST_METHOD'] === 'HEAD') {
            $body = '';
        }

        $this->buffer = "HTTP/{$protocol} {$status} {$reason}\r\n{$header}\r\n\r\n{$body}";
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
     * Write the prepared response
     *
     * @return void
     */
    public function write() {
        $env = $this->environment;
        $bytesWritten = @fwrite($env->socket, $this->buffer);

        if ($bytesWritten === strlen($this->buffer)) {
            $mustClose = $env->mustClose;
            $env->reactor->disable($env->writeWatcher);
            $env->server->resumeSocketControl($env->requestId, $mustClose);
        } elseif ($bytesWritten === false) {
            $mustClose = true;
            $env->reactor->disable($env->writeWatcher);
            $env->server->resumeSocketControl($env->requestId, $mustClose);
        } else {
            $this->buffer = substr($this->buffer, $bytesWritten);
            $env->reactor->enable($env->writeWatcher);
        }
    }
}

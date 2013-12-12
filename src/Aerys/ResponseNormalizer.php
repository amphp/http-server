<?php

namespace Aerys;

class ResponseNormalizer {

    /**
     *
     * $options = [
     *     'serverToken' => string,
     *     'autoReason' => bool,
     *     'defaultContentType' => string,
     *     'defaultTextCharset' => string,
     *     'dateHeader' => string,
     *     'requestsRemaining' => int,
     *     'forceClose' => bool
     * ];
     *
     * @throws \DomainException On invalid response elements
     */
    function normalize(Response $response, $request, array $options = []) {
        $dateHeader = NULL;
        $serverToken = NULL;
        $autoReason = NULL;
        $requestsRemaining = NULL;
        $forceClose = NULL;
        $keepAliveTimeout = NULL;
        $defaultContentType = 'text/html';
        $defaultTextCharset = 'utf-8';

        extract($options);
        //extract($options, $flags = EXTR_OVERWRITE | EXTR_PREFIX_ALL, $prefix = '__');

        $protocol = $request['SERVER_PROTOCOL'];
        $status = isset($response['status']) ? @intval($response['status']) : 200;
        $reason = isset($response['reason']) ? @trim($response['reason']) : '';
        $headers = empty($response['headers']) ? '' : $response['headers'];
        $body = isset($response['body']) ? $response['body'] : '';

        if ($status < 100 || $status > 599) {
            throw new \DomainException(
                'Invalid response status code'
            );
        }

        if ($autoReason && !($reason || $reason === '0')) {
            $reasonConstant = "Aerys\Reason::HTTP_{$status}";
            $reason = defined($reasonConstant) ? constant($reasonConstant) : '';
        }

        $headers = $headers ? $this->stringifyResponseHeaders($headers) : '';

        if ($status >= 200) {
            $requestConnHdr = isset($request['HTTP_CONNECTION']) ? $request['HTTP_CONNECTION'] : NULL;
            $shouldClose = $this->shouldClose($headers, $requestConnHdr, $requestsRemaining, $protocol, $forceClose);
            $headers = $this->normalizeConnectionHeader($headers, $shouldClose, $requestsRemaining, $keepAliveTimeout);
            $headers = $this->normalizeEntityHeaders($headers, $body, $defaultContentType, $defaultTextCharset);
        } else {
            $shouldClose = FALSE;
        }

        if ($serverToken) {
            $headers = $this->removeHeaderOccurrences($headers, 'Server');
            $headers .= "\r\nServer: {$serverToken}";
        }

        if ($dateHeader) {
            $headers = $this->removeHeaderOccurrences($headers, 'Date');
            $headers .= "\r\nDate: {$dateHeader}";
        }

        // This MUST happen AFTER entity header normalization or headers won't be
        // correct when responding to HEAD requests
        if ($request['REQUEST_METHOD'] === 'HEAD') {
            $body = '';
        }

        $headers = trim($headers);

        $response['status'] = $status;
        $response['reason'] = $reason;
        $response['headers'] = explode("\r\n", $headers);
        $response['body'] = $body;

        $reason = ($reason || $reason === '0') ? (' ' . $reason) : '';
        $rawHeaders = "HTTP/{$protocol} {$status}{$reason}\r\n{$headers}\r\n\r\n";

        return [$rawHeaders, $shouldClose];
    }

    private function stringifyResponseHeaders($headers) {
        if (is_array($headers)) {
            $headers = "\r\n" . implode("\r\n", array_map('trim', $headers));
        } else {
            throw new \DomainException(
                'Invalid response header array'
            );
        }

        return $headers;
    }

    private function shouldClose($headers, $requestConnHdr, $requestsRemaining, $protocol, $forceClose) {
        if ($forceClose) {
            $shouldClose = TRUE;
        } elseif ($requestsRemaining === 0) {
            $shouldClose = TRUE;
        } elseif ($requestConnHdr) {
            $shouldClose = stripos($requestConnHdr, 'close') !== FALSE;
        } elseif ($connHdrLine = $this->pluckConnectionHeaderLine($headers)) {
            $shouldClose = stripos($connHdrLine, 'close') !== FALSE;
        } else {
            $shouldClose = ($protocol == '1.0');
        }

        return $shouldClose;
    }

    private function pluckConnectionHeaderLine($headers) {
        $startPos = stripos($headers, "\r\nConnection:");

        if ($startPos === FALSE) {
            $line = NULL;
        } elseif ($endPos = strpos($headers, "\r\n", $startPos + 2)) {
            $line = substr($headers, $startPos, $endPos - $startPos);
        } else {
            $line = substr($headers, $startPos);
        }

        return $line;
    }

    private function normalizeConnectionHeader($headers, $shouldClose, $requestsRemaining, $keepAliveTimeout) {
        $headers = $this->removeHeaderOccurrences($headers, 'Connection');
        $headers .= $shouldClose ? "\r\nConnection: close" : "\r\nConnection: keep-alive";

        if (!$shouldClose && $keepAliveTimeout > 0) {
            $headers .= "\r\nKeep-Alive: timeout={$keepAliveTimeout}, max={$requestsRemaining}";
        }
        
        return $headers;
    }

    private function normalizeEntityHeaders($headers, $body, $defaultContentType, $defaultTextCharset) {
        if (!$body || is_scalar($body)) {
            $headers = $this->normalizeScalarContentLength($headers, $body);
        } elseif (is_resource($body)) {
            $headers = $this->normalizeResourceContentLength($headers, $body);
        } elseif ($body instanceof Writing\ByteRangeBody || $body instanceof Writing\MultiPartByteRangeBody) {
            // Headers from the static DocRoot handler are assumed to be correct; no change needed.
        } else {
            throw new \DomainException(
                'Invalid response body'
            );
        }

        // @TODO Add charset if not present in existing content-type header
        if (stripos($headers, "\r\nContent-Type:") === FALSE) {
            $headers .= "\r\nContent-Type: {$defaultContentType}; charset={$defaultTextCharset}";
        }

        return $headers;
    }

    private function normalizeScalarContentLength($headers, $body) {
        $headers = $this->removeHeaderOccurrences($headers, 'Content-Length');
        $headers .= "\r\nContent-Length: " . strlen($body);

        return $headers;
    }

    private function normalizeResourceContentLength($headers, $body) {
        fseek($body, 0, SEEK_END);
        $bodyLen = ftell($body);
        rewind($body);

        $headers = $this->removeHeaderOccurrences($headers, 'Content-Length');
        $headers .= "\r\nContent-Length: {$bodyLen}";

        return $headers;
    }

    private function removeHeaderOccurrences($headers, $field) {
        while (($clPos = stripos($headers, "\r\n{$field}:")) !== FALSE) {
            $lineEndPos = strpos($headers, "\r\n", $clPos + 2);
            $start = substr($headers, 0, $clPos);
            $end = $lineEndPos ? substr($headers, $lineEndPos) : '';
            $headers = $start . $end;
        }

        return $headers;
    }

}

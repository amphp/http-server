<?php

namespace Aerys;

/**
 * Create a router for use with Host instances
 *
 * @param array $options Router options
 * @return \Aerys\Router
 */
function router(array $options = []) {
    $router = new Router;
    foreach ($options as $key => $value) {
        $router->setOption($options);
    }
    return $router;
}

/**
 * Create a Websocket application action for attachment to a Host
 *
 * @param \Aerys\Websocket $app The websocket app to use
 * @param array $options Endpoint options
 * @return \Aerys\WebsocketEndpoint
 */
function websocket(Websocket $app, array $options = []) {
    $endpoint = new Rfc6455Endpoint($app);
    foreach ($options as $key => $value) {
        $endpoint->setOption($key, $value);
    }
    return $endpoint;
}

/**
 * Create a static file root for use with Host instances
 *
 * @param string $docroot The filesystem directory from which to serve documents
 * @param array $options Root options
 * @return \Aerys\Root\Root
 */
function root(string $docroot, array $options = []) {
    $reactor = \Amp\reactor();
    if ($reactor instanceof \Amp\NativeReactor) {
        $root = new Root\BlockingRoot($docroot, $reactor);
    } elseif ($reactor instanceof \Amp\UvReactor) {
        $root = new Root\UvRoot($docroot, $reactor);
    }

    $defaultMimeFile = __DIR__ ."/../etc/mime";
    if (!array_key_exists("mimeFile", $options) && file_exists($defaultMimeFile)) {
        $options["mimeFile"] = $defaultMimeFile;
    }
    foreach ($options as $key => $value) {
        $root->setOption($key, $value);
    }

    return $root;
}

/**
 * Does the specified header field exist in the multi-line header string?
 *
 * @param string $headers
 * @param string $field
 * @return bool
 */
function hasHeader($headers, $field) {
    $headers = "\r\n" . trim($headers);
    $field = "\r\n" . rtrim($field, " :") . ':';

    return (stripos($headers, $field) === false) ? false : true;
}

/**
 * Retrieve the value from the first occurence of the specified header field or NULL if nonexistent.
 *
 * @param string $headers
 * @param string $field
 * @return null|string
 */
function getHeader($headers, $field) {
    $field = rtrim($field, " :") . ':';
    $tok = strtok("\r\n" . $headers, "\r\n");
    while ($tok !== FALSE) {
        if (stripos($tok, $field) === 0) {
            return trim(substr($tok, strlen($field)));
        }
        $tok = strtok("\r\n");
    }

    return null;
}

/**
 * Retrieve all values for the specified header field (or an empty array if none are found)
 *
 * @param string $headers
 * @param string $field
 * @return array
 */
function getHeaderArray($headers, $field) {
    $field = rtrim($field, " :") . ':';
    $tok = strtok("\r\n" . $headers, "\r\n");
    $values = [];
    while ($tok !== FALSE) {
        if (stripos($tok, $field) === 0) {
            $values[] = trim(substr($tok, strlen($field)));
        }
        $tok = strtok("\r\n");
    }

    return $values;
}

/**
 * Replace any occurence of the $field header with the new $value
 *
 * @param string $headers
 * @param string $field
 * @param string $value
 * @return string
 */
function setHeader($headers, $field, $value) {
    $headers = removeHeader($headers, $field);
    $headers .= "\r\n{$field}: {$value}";

    return $headers;
}

/**
 * Append the specified header field value
 *
 * @param string $headers
 * @param string $field
 * @param string $value
 * @return string
 */
function addHeader($headers, $field, $value) {
    return "{$headers}\r\n{$field}: {$value}";
}

/**
 * Append the specified header line
 *
 * @param string $headers
 * @param string $line
 * @return string
 */
function addHeaderLine($headers, $line) {
    return "{$headers}\r\n{$line}";
}

/**
 * Remove all occurrences of the specified header $field
 *
 * @param string $headers
 * @param string $field
 * @return string
 */
function removeHeader($headers, $field) {
    $headers = trim($headers);
    if (empty($headers)) {
        return $headers;
    }

    if (stripos("\r\n{$headers}", "\r\n{$field}:") === false) {
        return $headers;
    }

    $newHeaders = [];
    $fieldKey = rtrim($field, " :") . ':';
    foreach (explode("\r\n", $headers) as $line) {
        if (stripos($line, $fieldKey) !== 0) {
            $newHeaders[] = $line;
        }
    }

    return $newHeaders ? implode("\r\n", $newHeaders) : '';
}

/**
 * Does the header $field exist AND contain the specified $value?
 *
 * @param string $headers
 * @param string $field
 * @param string $value
 * @return bool
 * @TODO Make this work when multiple headers of the same field are present in $headers
 */
function headerMatches(string $headers, string $field, string $value) {
    if (empty($headers)) {
        return false;
    }

    // Normalize values for searching
    $headers = "\r\n{$headers}";
    $field = "\r\n" . trim($field, "\r\n\t\x20:") . ":";

    // If the header isn't found at all we're finished
    if (($lineStartPos = \stripos($headers, $field)) === false) {
        return false;
    }

    $valueLineOffset = $lineStartPos + \strlen($field);
    $valueLine = (($lineEndPos = \stripos($headers, "\r\n", $lineStartPos + 4)) === false)
        ? \substr($headers, $valueLineOffset)
        : \substr($headers, $valueLineOffset, $lineEndPos - $valueLineOffset);

    return (\stripos($valueLine, $value) !== false);
}

/**
 * Is the specified reason phrase valid according to RFC7230?
 *
 * @TODO Validate reason phrase against RFC7230 ABNF
 * @param string $phrase An HTTP reason phrase
 * @return bool
 * @link https://tools.ietf.org/html/rfc7230#section-3.1.2
 */
function isValidReasonPhrase(string $phrase): bool {
    // reason-phrase  = *( HTAB / SP / VCHAR / obs-text )
    return true;
}

/**
 * Is the specified header field valid according to RFC7230?
 *
 * @TODO Validate field name against RFC7230 ABNF
 * @param string $field An HTTP header field name
 * @return bool
 * @link https://tools.ietf.org/html/rfc7230#section-3.2
 */
function isValidHeaderField(string $field): bool {
    // field-name     = token
    return true;
}

/**
 * Is the specified header value valid according to RFC7230?
 *
 * @TODO Validate field name against RFC7230 ABNF
 * @param string $value An HTTP header field value
 * @return bool
 * @link https://tools.ietf.org/html/rfc7230#section-3.2
 */
function isValidHeaderValue(string $value): bool {
    // field-value    = *( field-content / obs-fold )
    // field-content  = field-vchar [ 1*( SP / HTAB ) field-vchar ]
    // field-vchar    = VCHAR / obs-text
    //
    // obs-fold       = CRLF 1*( SP / HTAB )
    //                ; obsolete line folding
    //                ; see Section 3.2.4
    return true;
}

/**
 * Parses cookies into an array
 *
 * @param string $cookies
 * @return array with name => value pairs
 */
function parseCookie(string $cookies): array {
    $arr = [];

    foreach (explode("; ", $cookies) as $cookie) {
        if (strpos($cookie, "=") !== false) { // do not trigger notices for malformed cookies...
            list($name, $val) = explode("=", $cookie, 2);
            $arr[$name] = $val;
        }
    }

    return $arr;
}
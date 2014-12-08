<?php

namespace Aerys;

/**
 * Parse server command line options
 *
 * Returns an array with the following keys:
 *
 *  - help
 *  - debug
 *  - config
 *  - workers
 *  - remote
 *
 * @return array
 */
function parseCommandLineOptions() {
    $shortOpts = 'hdc:w:r:';
    $longOpts = ['help', 'debug', 'config:', 'workers:', 'remote:'];
    $parsedOpts = getopt($shortOpts, $longOpts);
    $shortOptMap = [
        'c' => 'config',
        'w' => 'workers',
        'r' => 'remote',
    ];

    $options = [
        'config' => '',
        'workers' => 0,
        'remote' => '',
    ];

    foreach ($parsedOpts as $key => $value) {
        $key = empty($shortOptMap[$key]) ? $key : $shortOptMap[$key];
        if (isset($options[$key])) {
            $options[$key] = $value;
        }
    }

    $options['debug'] = isset($parsedOpts['d']) || isset($parsedOpts['debug']);
    $options['help'] = isset($parsedOpts['h']) || isset($parsedOpts['help']);

    return $options;
}

/**
 * Count the number of available CPU cores on this system
 *
 * @return int
 */
function countCpuCores() {
    $os = (stripos(PHP_OS, "WIN") === 0) ? "win" : strtolower(trim(shell_exec("uname")));

    switch ($os) {
        case "win":
        $cmd = "wmic cpu get NumberOfCores";
        break;
        case "linux":
        $cmd = "cat /proc/cpuinfo | grep processor | wc -l";
        break;
        case "freebsd":
        $cmd = "sysctl -a | grep 'hw.ncpu' | cut -d ':' -f2";
        break;
        case "darwin":
        $cmd = "sysctl -a | grep 'hw.ncpu:' | awk '{ print $2 }'";
        break;
        default:
        $cmd = NULL;
    }

    $execResult = $cmd ? shell_exec($cmd) : 1;

    if ($os === 'win') {
        $execResult = explode("\n", $execResult)[1];
    }

    $cores = intval(trim($execResult));

    return $cores;
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
 * Does the specified header $field exist AND contain the specified $value?
 *
 * @param string $headers
 * @param string $field
 * @param string $value
 * @return bool
 */
function headerMatches($headers, $field, $value) {
    $headers = trim($headers);
    if (empty($headers)) {
        return false;
    }

    $field = "\r\n" . rtrim($field, " :") . ':';
    if (stripos("\r\n{$headers}", "\r\n{$field}") === false) {
        return false;
    }

    $fieldLen = strlen($field);
    $tok = strtok($headers, "\r\n");
    while ($tok !== false) {
        if (stripos($tok, $field) === 0 &&
            strcasecmp($value, trim(substr($tok, $fieldLen))) === 0
        ) {
            return true;
        } else {
            $tok = strtok("\r\n");
        }
    }

    return false;
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

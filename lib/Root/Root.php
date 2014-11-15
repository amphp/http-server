<?php

namespace Aerys\Root;

use Amp\Future;
use Amp\Reactor;
use Aerys\Status;
use Aerys\Server;
use Aerys\ServerObserver;

abstract class Root implements ServerObserver {
    const OP_INDEXES = 'indexes';
    const OP_ETAG_MODE = 'etagmode';
    const OP_EXPIRES_PERIOD = 'expiresperiod';
    const OP_MIME_TYPES = 'mimetypes';
    const OP_DEFAULT_MIME_TYPE = 'defaultmimetype';
    const OP_DEFAULT_CHARSET = 'defaultcharset';
    const OP_CACHE_TTL = 'cachettl';
    const OP_CACHE_MAX_BUFFER_ENTRIES = 'cachemaxbufferentries';
    const OP_CACHE_MAX_BUFFER_ENTRY_SIZE = 'cachemaxbufferentrysize';

    const ETAG_NONE = 0;
    const ETAG_SIZE = 1;
    const ETAG_INODE = 2;
    const ETAG_ALL = 3;

    private static $PRECONDITION_NOT_MODIFIED = 1;
    private static $PRECONDITION_FAILED = 2;
    private static $PRECONDITION_IF_RANGE_OK = 3;
    private static $PRECONDITION_IF_RANGE_FAILED = 4;
    private static $PRECONDITION_OK = 5;

    private $reactor;
    private $responderFactory;
    private $fileEntryCache;
    private $rootPath;
    private $mimeTypes = [];
    private $indexes = ['index.html', 'index.htm'];
    private $etagMode = self::ETAG_SIZE;
    private $expiresPeriod = 3600;
    private $defaultMimeType = 'text/plain';
    private $defaultCharset = 'utf-8';
    private $cacheTtl = 10;
    private $cacheMaxBufferEntries = 50;
    private $cacheMaxBufferEntrySize = 500000;
    private $debug;

    private $now;
    private $cache = [];
    private $cacheTimeouts = [];
    private $cacheBufferEntryCount = 0;
    private $cacheCollectWatcher;
    private $isCacheCollectWatcherEnabled;

    /**
     * Generate a FileEntry instance from the given path and index file list
     *
     * If the implementation determines the filesystem path to be a directory it is responsible
     * for iterating over the index list to find a match.
     *
     * Upon completion implementations must invoke the $onComplete callback with the completed
     * FileEntry instance or NULL if the file could not be found.
     *
     * @param string $path The path for which to generate a file entry
     * @param array $indexes An array of possible directory index file names
     * @param callable $onComplete The error-first callback to invoke upon completion
     * @return void
     */
    abstract protected function generateFileEntry($path, array $indexes, callable $onComplete);

    /**
     * Buffer string file contents from a file handle
     *
     * NOTE: The $onComplete callback here IS NOT an error first callback. Only a single value
     * is passed upon completion. If buffering failed then FALSE must be passed to $onComplete.
     * Otherwise the string buffer must be passed to the callback.
     *
     * @param mixed $handle A file handle
     * @param int $length The expected length in bytes of the eventual buffer
     * @param callable $onComplete The callback to invoke upon completion
     * @return void
     */
    abstract protected function bufferFile($handle, $length, callable $onComplete);

    /**
     * @param Reactor $reactor
     * @param ResponderFactory $responderFactory
     * @param string $rootPath
     */
    public function __construct(Reactor $reactor, ResponderFactory $responderFactory, $rootPath) {
        $rootPath = str_replace('\\', '/', $rootPath);
        if (is_readable($rootPath) && is_dir($rootPath)) {
            $this->rootPath = rtrim(realpath($rootPath), '/');
        } else {
            throw new \InvalidArgumentException(
                sprintf('Document root requires a readable directory: %s', $rootPath)
            );
        }

        $this->reactor = $reactor;
        $this->responderFactory = $responderFactory;
        $cacheCollector = function() { $this->collectStaleCacheEntries(); };
        $this->cacheCollectWatcher = $reactor->repeat($cacheCollector, $msInterval = 1000);
        $reactor->disable($this->cacheCollectWatcher);
    }

    /**
     * Respond to HTTP requests for filesystem resources
     *
     * @param array $request
     * @return mixed array|\Amp\Promise
     */
    public function __invoke(array $request) {
        if (!$path = $this->normalizePath($request['REQUEST_URI_PATH'])) {
            return ['status' => Status::FORBIDDEN];
        }

        $rootRequest = new RootRequest;
        $rootRequest->path = $path;
        $rootRequest->request = $request;
        $rootRequest->promisor = $promisor = new Future($this->reactor);

        if ($rootRequest->fileEntry = $this->fetchCachedFileEntry($path, $request)) {
            $this->respond($rootRequest);
        } else {
            $this->generateFileEntry($path, $this->indexes, function($error, $result) use ($rootRequest) {
                if ($error) {
                    $rootRequest->promisor->fail($error);
                } elseif ($result) {
                    $this->onFileEntry($rootRequest, $result);
                } else {
                    $rootRequest->promisor->succeed([
                        'status' => Status::NOT_FOUND,
                        'header' => 'Content-Type: text/html; charset=utf-8',
                        'body'   => '<html><body><h1>404 Not Found</h1></body></html>',
                    ]);
                }
            });
        }

        return $promisor;
    }

    private function normalizePath($requestUriPath) {
        // Windows seems to bork without this (and the rootPath is normalized to forward slashes)
        $requestUriPath = str_replace('\\', '/', $requestUriPath);

        // 'REQUEST_URI_PATH' always contains a leading slash
        $path = $this->rootPath . $requestUriPath;

        $path = $this->removeDotPathSegments($path);

        // Protect against dot segment path traversal above the document root by
        // verifying that the final path actually resides in the document root.
        return (strpos($path, $this->rootPath) === 0) ? $path : false;
    }

    private function removeDotPathSegments($path) {
        if (strpos($path, '/.') === false) {
            return $path;
        }

        $inputBuffer = $path;
        $outputStack = [];

        /**
         * 2.  While the input buffer is not empty, loop as follows:
         */
        while ($inputBuffer != '') {
            /**
             * A.  If the input buffer begins with a prefix of "../" or "./",
             *     then remove that prefix from the input buffer; otherwise,
             */
            if (strpos($inputBuffer, "./") === 0) {
                $inputBuffer = substr($inputBuffer, 2);
                continue;
            }
            if (strpos($inputBuffer, "../") === 0) {
                $inputBuffer = substr($inputBuffer, 3);
                continue;
            }

            /**
             * B.  if the input buffer begins with a prefix of "/./" or "/.",
             *     where "." is a complete path segment, then replace that
             *     prefix with "/" in the input buffer; otherwise,
             */
            if ($inputBuffer === "/.") {
                $outputStack[] = '/';
                break;
            }
            if (substr($inputBuffer, 0, 3) === "/./") {
                $inputBuffer = substr($inputBuffer, 2);
                continue;
            }

            /**
             * C.  if the input buffer begins with a prefix of "/../" or "/..",
             *     where ".." is a complete path segment, then replace that
             *     prefix with "/" in the input buffer and remove the last
             *     segment and its preceding "/" (if any) from the output
             *     buffer; otherwise,
             */
            if ($inputBuffer === "/..") {
                array_pop($outputStack);
                $outputStack[] = '/';
                break;
            }
            if (substr($inputBuffer, 0, 4) === "/../") {
                array_pop($outputStack);
                $inputBuffer = substr($inputBuffer, 3);
                continue;
            }

            /**
             * D.  if the input buffer consists only of "." or "..", then remove
             *     that from the input buffer; otherwise,
             */
            if ($inputBuffer === '.' || $inputBuffer === '..') {
                break;
            }

            /**
             * E.  move the first path segment in the input buffer to the end of
             *     the output buffer, including the initial "/" character (if
             *     any) and any subsequent characters up to, but not including,
             *     the next "/" character or the end of the input buffer.
             */
            if (($slashPos = stripos($inputBuffer, '/', 1)) === false) {
                $outputStack[] = $inputBuffer;
                break;
            } else {
                $outputStack[] = substr($inputBuffer, 0, $slashPos);
                $inputBuffer = substr($inputBuffer, $slashPos);
            }
        }

        return implode($outputStack);
    }

    private function fetchCachedFileEntry($path, array $request) {
        // We specifically allow users to bypass cached representations in debug mode by
        // using their browser's "force refresh" functionality. This lets us avoid the
        // annoyance of stale file representations being served for a few seconds after
        // changes have been written to disk.
        if (!$this->debug) {
            return isset($this->cache[$path]) ? $this->cache[$path] : null;
        } elseif (isset($request['HTTP_CACHE_CONTROL']) &&
            stripos($request['HTTP_CACHE_CONTROL'], 'no-cache') !== false
        ) {
            return null;
        } elseif (isset($request['HTTP_PRAGMA']) &&
            stripos($request['HTTP_PRAGMA'], 'no-cache') !== false
        ) {
            return null;
        } else {
            return isset($this->cache[$path]) ? $this->cache[$path] : null;
        }
    }

    private function onFileEntry(RootRequest $rootRequest, FileEntry $fileEntry) {
        $etag = $fileEntry->path . $fileEntry->mtime;

        if ($this->etagMode & self::ETAG_SIZE) {
            $etag .= $fileEntry->size;
        }
        if ($this->etagMode & self::ETAG_INODE) {
            $etag .= $fileEntry->inode;
        }
        $fileEntry->etag = md5($etag);

        // Specifically use the original request path to reference this file in
        // the cache because the file entry path may differ if it's reflecting
        // a directory index file.
        $this->cacheFileEntry($rootRequest->path, $fileEntry);

        $rootRequest->fileEntry = $fileEntry;

        $this->respond($rootRequest);
    }

    private function respond(RootRequest $rootRequest) {
        $promisor = $rootRequest->promisor;
        $request = $rootRequest->request;
        $method = $request['REQUEST_METHOD'];

        if ($method === 'OPTIONS') {
            return $promisor->succeed([
                'status' => Status::OK,
                'header' => [
                    'Allow: GET, HEAD, OPTIONS',
                    'Accept-Ranges: bytes',
                ]
            ]);
        }

        if (!($method === 'GET' || $method === 'HEAD')) {
            return $promisor->succeed([
                'status' => Status::METHOD_NOT_ALLOWED,
                'header' => ['Allow: GET, HEAD, OPTIONS'],
            ]);
        }

        $fileEntry = $rootRequest->fileEntry;
        $mtime = $fileEntry->mtime;
        $etag = $fileEntry->etag;
        $preCode = $this->checkPreconditions($request, $mtime, $etag);

        if ($preCode === self::$PRECONDITION_NOT_MODIFIED) {
            $response = $this->makeNotModifiedResponse($mtime, $etag);
            return $promisor->succeed($response);
        } elseif ($preCode === self::$PRECONDITION_FAILED) {
            $response = ['status' => Status::PRECONDITION_FAILED];
            return $promisor->succeed($response);
        }

        $headerLines = $this->buildResponseHeaders($fileEntry);
        $responder = $this->responderFactory->make($fileEntry, $headerLines, $request);

        $promisor->succeed($responder);
    }

    private function checkPreconditions(array $request, $mtime, $etag) {
        $ifMatch = isset($request['HTTP_IF_MATCH']) ? $request['HTTP_IF_MATCH'] : '';
        if ($ifMatch && stripos($ifMatch, $etag) === false) {
            return self::$PRECONDITION_FAILED;
        }

        $ifNoneMatch = isset($request['HTTP_IF_NONE_MATCH']) ? $request['HTTP_IF_NONE_MATCH'] : '';
        if (stripos($ifNoneMatch, $etag) !== false) {
            return self::$PRECONDITION_NOT_MODIFIED;
        }

        $ifModifiedSince = isset($request['HTTP_IF_MODIFIED_SINCE'])
            ? @strtotime($request['HTTP_IF_MODIFIED_SINCE'])
            : 0;

        if ($ifModifiedSince && $mtime > $ifModifiedSince) {
            return self::$PRECONDITION_NOT_MODIFIED;
        }

        $ifUnmodifiedSince = isset($request['HTTP_IF_UNMODIFIED_SINCE'])
            ? @strtotime($request['HTTP_IF_UNMODIFIED_SINCE'])
            : 0;

        if ($ifUnmodifiedSince && $mtime > $ifUnmodifiedSince) {
            return self::$PRECONDITION_FAILED;
        }

        if (!isset($request['HTTP_IF_RANGE'], $request['HTTP_RANGE'])) {
            return self::$PRECONDITION_OK;
        }

        /**
         * If-Range headers may be either an HTTP timestamp or an Etag:
         *
         *     If-Range = "If-Range" ":" ( entity-tag | HTTP-date )
         *
         * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.27
         */
        $ifRange = $request['HTTP_IF_RANGE'];
        if ($httpDate = @strtotime($ifRange)) {
            return ($httpDate > $mtime)
                ? self::$PRECONDITION_IF_RANGE_OK
                : self::$PRECONDITION_IF_RANGE_FAILED;
        }

        // If the If-Range header was not an HTTP date we assume it's an Etag
        return ($etag === $ifRange)
            ? self::$PRECONDITION_IF_RANGE_OK
            : self::$PRECONDITION_IF_RANGE_FAILED;
    }

    private function makeNotModifiedResponse($mtime, $etag = null) {
        $headers[] = 'Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' UTC';
        if ($etag) {
            $headers[] = "ETag: {$etag}";
        }

        return [
            'status' => Status::NOT_MODIFIED,
            'header' => $headers,
        ];
    }

    private function buildResponseHeaders(FileEntry $fileEntry) {
        $headerLines = [
            'Accept-Ranges: bytes',
            'Cache-Control: public',
            "Content-Length: {$fileEntry->size}",
        ];

        $ext = pathinfo($fileEntry->path, PATHINFO_EXTENSION);
        if (empty($ext)) {
            $mimeType = $this->defaultMimeType;
        } else {
            $ext = strtolower($ext);
            $mimeType = isset($this->mimeTypes[$ext])
                ? $this->mimeTypes[$ext]
                : $this->defaultMimeType;
        }

        if (stripos($mimeType, 'text/') === 0 && stripos($mimeType, 'charset=') === false) {
            $mimeType .= "; charset={$this->defaultCharset}";
        }

        $headerLines[] = "Content-Type: {$mimeType}";

        if ($fileEntry->etag) {
            $headerLines[] = "Etag: {$fileEntry->etag}";
        }

        if ($this->expiresPeriod > 0) {
            $expiry =  time() + $this->expiresPeriod;
            $headerLines[] = 'Expires: ' . gmdate('D, d M Y H:i:s', $expiry) . ' UTC';
        } else {
            $headerLines[] = 'Expires: 0';
        }

        $headerLines[] = 'Last-Modified: ' . gmdate('D, d M Y H:i:s', $fileEntry->mtime) . ' UTC';

        return $headerLines;
    }

    /**
     * Set multiple document root options
     *
     * @param array $options Key-value array mapping option name keys to values
     * @return void
     */
    final public function setAllOptions(array $options) {
        foreach ($options as $option => $value) {
            $this->setOption($option, $value);
        }
    }

    /**
     * Set a document root option
     *
     * @param string $option The option key (case-insensitve)
     * @param mixed $value The option value to assign
     * @throws \DomainException On unrecognized option key
     * @return void
     */
    final public function setOption($option, $value) {
        switch(strtolower($option)) {
            case self::OP_INDEXES:
                $this->setIndexes($value);
                break;
            case self::OP_ETAG_MODE:
                $this->setEtagMode($value);
                break;
            case self::OP_EXPIRES_PERIOD:
                $this->setExpiresPeriod($value);
                break;
            case self::OP_MIME_TYPES:
                $this->setMimeTypes($value);
                break;
            case self::OP_DEFAULT_MIME_TYPE:
                $this->setDefaultMimeType($value);
                break;
            case self::OP_DEFAULT_CHARSET:
                $this->setDefaultTextCharset($value);
                break;
            case self::OP_CACHE_TTL:
                $this->setCacheTtl($value);
                break;
            case self::OP_CACHE_MAX_BUFFER_ENTRIES:
                $this->setCacheMaxBufferEntries($value);
                break;
            case self::OP_CACHE_MAX_BUFFER_ENTRY_SIZE:
                $this->setCacheMaxBufferEntrySize($value);
                break;
            default:
                throw new \DomainException(
                    "Unknown root option: {$option}"
                );
        }
    }

    private function setIndexes($indexes) {
        if (is_string($indexes)) {
            $indexes = array_map('trim', explode(" ", $indexes));
        } elseif (!is_array($indexes)) {
            throw new \InvalidArgumentException(
                sprintf('Array or string required for root index filenames: %s provided', gettype($indexes))
            );
        } else {
            foreach ($indexes as $index) {
                if (!is_string($index)) {
                    throw new \InvalidArgumentException(
                        sprintf('Array of string index filenames required: %s provided', gettype($index))
                    );
                }
            }
        }

        $this->indexes = array_filter($indexes);
    }

    private function setEtagMode($mode) {
        $this->etagMode = (int) $mode;
    }

    private function setExpiresPeriod($seconds) {
        $seconds = (int) $seconds;
        if ($seconds < 0) {
            $seconds = 0;
        }
        $this->expiresPeriod = $seconds;
    }

    private function setMimeTypes(array $mimeTypes) {
        foreach ($mimeTypes as $ext => $type) {
            $ext = strtolower(ltrim($ext, '.'));
            $this->mimeTypes[$ext] = $type;
        }
    }

    private function setDefaultMimeType($mimeType) {
        if ($mimeType && is_string($mimeType)) {
            $this->defaultMimeType = $mimeType;
        } else {
            throw new \InvalidArgumentException(
                'Default mime type expects a non-empty string'
            );
        }
    }

    private function setDefaultCharset($charset) {
        if ($charset && is_string($charset)) {
            $this->defaultCharset = $charset;
        } else {
            throw new \InvalidArgumentException(
                'Default charset expects a non-empty string'
            );
        }
    }

    private function setCacheTtl($seconds) {
        $seconds = (int) $seconds;
        if ($seconds < 1) {
            $seconds = 10;
        }
        $this->cacheTtl = $seconds;
    }

    private function setCacheMaxBufferEntries($count) {
        $entries = (int) $count;
        if ($entries < 0) {
            $entries = 0;
        }
        $this->cacheMaxBufferEntries = $entries;
    }

    private function setCacheMaxBufferEntrySize($bytes) {
        $bytes = (int) $bytes;
        if ($bytes < 1) {
            $bytes = 500000;
        }
        $this->cacheMaxBufferEntrySize = $bytes;
    }

    private function collectStaleCacheEntries() {
        $this->now = $now = time();
        foreach ($this->cacheTimeouts as $path => $timeout) {
            if ($now > $timeout) {
                $fileEntry = $this->cache[$path];
                unset(
                $this->cacheTimeouts[$path],
                $this->cache[$path]
                );
                $this->cacheBufferEntryCount -= isset($fileEntry->buffer);
            } else {
                break;
            }
        }

        if (empty($this->cacheTimeouts)) {
            $this->isCacheCollectWatcherEnabled = false;
            $this->reactor->disable($this->cacheCollectWatcher);
        }
    }

    private function cacheFileEntry($key, FileEntry $fileEntry) {
        // It's important to use unset() here because we need to
        // place the expiration timeout at the back of the queue.
        unset($this->cacheTimeouts[$key]);

        if (!$this->isCacheCollectWatcherEnabled) {
            $this->now = time();
            $this->reactor->enable($this->cacheCollectWatcher);
            $this->isCacheCollectWatcherEnabled = true;
        }

        $this->cache[$key] = $fileEntry;
        $this->cacheTimeouts[$key] = $this->now + $this->cacheTtl;

        if ($fileEntry->size > $this->cacheMaxBufferEntrySize) {
            return;
        }

        if ($this->cacheBufferEntryCount >= $this->cacheMaxBufferEntries) {
            return;
        }

        $this->cacheBufferEntryCount++;
        $this->bufferFile($fileEntry->handle, $fileEntry->size, function($buffer) use ($fileEntry) {
            if ($buffer !== false) {
                $fileEntry->buffer = $buffer;
            } else {
                $this->cacheBufferEntryCount--;
            }
        });
    }

    /**
     * Receive notifications from the server when it starts/stops
     *
     * @param \Aerys\Server $server
     * @param int $event
     * @return void
     */
    final public function onServerUpdate(Server $server, $event) {
        switch ($event) {
            case Server::STARTING:
                $this->debug = $server->isInDebugMode();
                break;
            case Server::STOPPED:
                $this->reactor->disable($this->cacheCollectWatcher);
                $this->cache = [];
                $this->cacheTimeouts = [];
                $this->cacheBufferEntryCount = 0;
                $this->isCacheCollectWatcherEnabled = false;
                break;
        }
    }
}

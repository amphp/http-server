<?php

namespace Aerys\Root;

use Amp\Future;
use Amp\Reactor;
use Aerys\Status;
use Aerys\Server;
use Aerys\ServerObserver;
use const Aerys\HTTP_STATUS;

abstract class Root implements ServerObserver {
    const OP_INDEXES = 'indexes';
    const OP_ETAG_USE_INODE = 'useetaginode';
    const OP_EXPIRES_PERIOD = 'expiresperiod';
    const OP_MIME_TYPES = 'mimetypes';
    const OP_DEFAULT_MIME_TYPE = 'defaultmimetype';
    const OP_DEFAULT_CHARSET = 'defaultcharset';
    const OP_CACHE_TTL = 'cachettl';
    const OP_CACHE_MAX_BUFFERS = 'cachemaxbuffers';
    const OP_CACHE_MAX_BUFFER_SIZE = 'cachemaxbuffersize';
    const OP_AGGRESSIVE_CACHE_HEADER_ENABLED = 'aggressivecacheheaderenabled';
    const OP_AGGRESSIVE_CACHE_MULTIPLIER = 'aggressivecachemultiplier';

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
    private $multipartBoundary;
    private $multipartHeaderTemplate;
    private $now;
    private $cache = [];
    private $cacheTimeouts = [];
    private $cacheBufferEntryCount = 0;
    private $cacheCollectWatcher;
    private $isCacheCollectWatcherEnabled;

    private $indexes = ['index.html', 'index.htm'];
    private $useEtagInode = true;
    private $expiresPeriod = 3600;
    private $defaultMimeType = 'text/plain';
    private $defaultCharset = 'utf-8';
    private $cacheTtl = 10;
    private $cacheMaxBufferEntries = 50;
    private $cacheMaxBufferEntrySize = 524288;
    private $aggressiveCacheHeaderEnabled = false;
    private $aggressiveCacheMultiplier = 0.9;
    private $debug;

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
        $this->multipartBoundary = uniqid('', true);
        $this->multipartHeaderTemplate = "\r\n--%s\r\nContent-Type: %s\r\nContent-Range: bytes %d-%d/%d\r\n\r\n";
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
            return ['status' => HTTP_STATUS["FORBIDDEN"]];
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
                        'status' => HTTP_STATUS["NOT_FOUND"],
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
        $etag = "{$fileEntry->path}{$fileEntry->mtime}{$fileEntry->size}";

        if ($this->useEtagInode) {
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
                'status' => HTTP_STATUS["OK"],
                'header' => [
                    'Allow: GET, HEAD, OPTIONS',
                    'Accept-Ranges: bytes',
                ]
            ]);
        }

        if (!($method === 'GET' || $method === 'HEAD')) {
            return $promisor->succeed([
                'status' => HTTP_STATUS["METHOD_NOT_ALLOWED"],
                'header' => ['Allow: GET, HEAD, OPTIONS'],
            ]);
        }

        $fileEntry = $rootRequest->fileEntry;
        $mtime = $fileEntry->mtime;
        $etag = $fileEntry->etag;
        $size = $fileEntry->size;

        $preCode = $this->checkPreconditions($request, $mtime, $etag);

        if ($preCode === self::$PRECONDITION_NOT_MODIFIED) {
            $response = $this->makeNotModifiedResponse($mtime, $etag);
        } elseif ($preCode === self::$PRECONDITION_FAILED) {
            $response = ['status' => HTTP_STATUS["PRECONDITION_FAILED"]];
        } elseif ($preCode === self::$PRECONDITION_IF_RANGE_FAILED || empty($request['HTTP_RANGE'])) {
            $headerLines = $this->buildNonRangeHeaders($fileEntry);
            $response = $this->responderFactory->make($fileEntry, $headerLines, $request);
        } elseif ($range = $this->normalizeByteRanges($size, $request['HTTP_RANGE'])) {
            $headerLines = $this->buildRangeHeaders($fileEntry, $range);
            $response = $this->responderFactory->make($fileEntry, $headerLines, $request, $range);
        } else {
            $response = [
                'status' => HTTP_STATUS["REQUESTED_RANGE_NOT_SATISFIABLE"],
                'header' => "Content-Range: */{$size}",
            ];
        }

        $promisor->succeed($response);
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
            'status' => HTTP_STATUS["NOT_MODIFIED"],
            'header' => $headers,
        ];
    }

    private function buildNonRangeHeaders(FileEntry $fileEntry) {
        $headerLines = $this->buildCommonHeaders($fileEntry);
        $headerLines[] = "Content-Length: {$fileEntry->size}";
        $headerLines[] = 'Content-Type: ' . $this->selectMimeTypeFromPath($fileEntry->path);

        return $headerLines;
    }

    private function buildCommonHeaders(FileEntry $fileEntry) {
        $headerLines = [
            'Accept-Ranges: bytes',
            'Cache-Control: public',
        ];

        if ($fileEntry->etag) {
            $headerLines[] = "Etag: {$fileEntry->etag}";
        }

        $canCache = ($this->expiresPeriod > 0);

        if ($canCache && $this->aggressiveCacheHeaderEnabled) {
            $postCheck = $this->now + (int) ($this->expiresPeriod * $this->aggressiveCacheMultiplier);
            $preCheck = $this->now + $this->expiresPeriod;
            $expiry = $this->expiresPeriod;
            $headerLines[] = "Cache-Control: post-check={$postCheck}, pre-check={$preCheck}, max-age={$expiry}";
        } elseif ($canCache) {
            $expiry =  $this->now + $this->expiresPeriod;
            $headerLines[] = 'Expires: ' . gmdate('D, d M Y H:i:s', $expiry) . ' UTC';
        } else {
            $headerLines[] = 'Expires: 0';
        }

        $headerLines[] = 'Last-Modified: ' . gmdate('D, d M Y H:i:s', $fileEntry->mtime) . ' UTC';

        return $headerLines;
    }

    private function selectMimeTypeFromPath($path) {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
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

        return $mimeType;
    }

    /**
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.35
     */
    private function normalizeByteRanges($size, $rawRanges) {
        $rawRanges = str_ireplace([' ', 'bytes='], '', $rawRanges);
        $rawRanges = explode(',', $rawRanges);

        $ranges = [];

        foreach ($rawRanges as $range) {
            // If a range is missing the dash separator it's malformed; pull out here.
            if (false === strpos($range, '-')) {
                return null;
            }

            list($startPos, $endPos) = explode('-', rtrim($range));

            if ($startPos === '' && $endPos === '') {
                return null;
            } elseif ($startPos === '' && $endPos !== '') {
                // The -1 is necessary and not a hack because byte ranges are inclusive and start
                // at 0. DO NOT REMOVE THE -1.
                $startPos = $size - $endPos - 1;
                $endPos = $size - 1;
            } elseif ($endPos === '' && $startPos !== '') {
                $startPos = (int) $startPos;
                // The -1 is necessary and not a hack because byte ranges are inclusive and start
                // at 0. DO NOT REMOVE THE -1.
                $endPos = $size - 1;
            } else {
                $startPos = (int) $startPos;
                $endPos = (int) $endPos;
            }

            // If the requested range(s) can't be satisfied we're finished
            if ($startPos >= $size || $endPos < $startPos || $endPos < 0) {
                return null;
            }

            $ranges[] = [$startPos, $endPos];
        }

        $rangeStruct = new Range;
        $rangeStruct->boundary = $this->multipartBoundary;
        $rangeStruct->headerTemplate = $this->multipartHeaderTemplate;
        $rangeStruct->ranges = $ranges;

        return $rangeStruct;
    }

    private function buildRangeHeaders(FileEntry $fileEntry, Range $range) {
        $size = $fileEntry->size;
        $range->contentType = $mime = $this->selectMimeTypeFromPath($fileEntry->path);
        $headerLines = $this->buildCommonHeaders($fileEntry);
        $rangeArray = $range->ranges;

        if (isset($rangeArray[1])) {
            $headers[] = 'Content-Length: ' . $this->calculateMultipartLength($rangeArray, $size, $mime);
            $headers[] = "Content-Type:  multipart/byteranges; boundary={$this->multipartBoundary}";
        } else {
            list($startPos, $endPos) = $rangeArray[0];
            $headerLines[] = 'Content-Length: ' . ($endPos - $startPos);
            $headerLines[] = "Content-Range: bytes {$startPos}-{$endPos}/{$size}";
            $headerLines[] = "Content-Type: {$mime}";
        }

        return $headerLines;
    }

    private function calculateMultipartLength($ranges, $size, $mime) {
        $totalSize = 0;
        $boundarySize = strlen($this->multipartBoundary);
        $templateSize = strlen($this->multipartHeaderTemplate) - 10; // Don't count sprintf format strings
        $mimeSize = strlen($mime);
        $sizeSize = strlen($size);

        $baseHeaderSize = $boundarySize + $mimeSize + $sizeSize + $templateSize;

        foreach ($ranges as list($startPos, $endPos)) {
            $totalSize += ($endPos - $startPos);
            $totalSize += $baseHeaderSize;
            $totalSize += strlen($startPos);
            $totalSize += strlen($endPos);
            $totalSize += 2; // <-- Extra \r\n after range content
        }

        $closingBoundarySize = $boundarySize + 4; // --{$boundary}--
        $totalSize += $closingBoundarySize;

        return $totalSize;
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
            case self::OP_ETAG_USE_INODE:
                $this->setUseEtagInode($value);
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
            case self::OP_CACHE_MAX_BUFFERS:
                $this->setCacheMaxBufferEntries($value);
                break;
            case self::OP_CACHE_MAX_BUFFER_SIZE:
                $this->setCacheMaxBufferEntrySize($value);
                break;
            case self::OP_AGGRESSIVE_CACHE_HEADER_ENABLED:
                $this->setAggressiveCacheHeaderEnabled($value);
                break;
            case self::OP_AGGRESSIVE_CACHE_MULTIPLIER:
                $this->setAggressiveCacheMultiplier($value);
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

    private function setUseEtagInode($useInode) {
        $this->useEtagInode = (bool) $useInode;
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

    private function setAggressiveCacheHeaderEnabled($bool) {
        $this->aggressiveCacheHeaderEnabled = (bool) $bool;
    }

    private function setAggressiveCacheMultiplier($multiplier) {
        if (is_float($multiplier) && $multiplier < 1) {
            $this->aggressiveCacheMultiplier = $multiplier;
        } else {
            throw new \InvalidArgumentException(
                "Aggressive cache multiplier expects a float < 1; {$multiplier} specified"
            );
        }
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
     * @return void
     */
    final public function onServerUpdate(Server $server) {
        switch ($server->getState()) {
            case Server::STARTING:
                $this->debug = $server->getOption(Server::OP_DEBUG);
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

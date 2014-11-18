<?php

namespace Aerys\Root;

use Amp\Reactor;
use Amp\Success;
use Aerys\Responder;
use Aerys\ResponderStruct;
use Aerys\ClientGoneException;
use Aerys\Status;
use Aerys\Server;
use Aerys\ServerObserver;

class NaiveRoot implements Root, ServerObserver {
    private $reactor;
    private $rootPath;
    private $mimeTypes;
    private $indexes = ['index.html', 'index.htm'];
    private $etagFlags = self::ETAG_ALL;
    private $expiresPeriod = 3600;
    private $defaultMimeType = 'text/plain';
    private $defaultCharset = 'utf-8';
    private $enableCache = true;
    private $cacheTtl = 5;
    private $maxCacheEntries = 100;
    private $maxCacheEntrySize = 1048576;

    private $now;
    private $cache = [];
    private $cacheTimeouts = [];
    private $cacheCount = 0;
    private $cacheCollectWatcher;
    private $isCacheWatcherEnabled;

    private $multipartBoundary;
    private $multipartTemplate = "--%s\r\nContent-Type: %s\r\nContent-Range: bytes %d-%d/%d\r\n\r\n";

    private $http404;
    private $http412;
    private $http405;

    private static $PRE_NOT_MODIFIED = 1;
    private static $PRE_FAILED = 2;
    private static $PRE_IF_RANGE_OK = 3;
    private static $PRE_IF_RANGE_FAILED = 4;
    private static $PRE_PASS = 5;
    private static $CACHE_HANDLE = 1;
    private static $CACHE_BUFFER = 2;

    public function __construct(Reactor $reactor, $rootPath, $mimePath = null) {
        $this->reactor = $reactor;
        $this->initRoot($rootPath);
        $this->initMime($mimePath ?: __DIR__ . '/../../etc/mime-types');
        $this->multipartBoundary = uniqid('', true);
        $this->http404 = [
            'status' => Status::NOT_FOUND,
            'header' => ['Content-Type: text/html; charset=utf-8'],
            'body'   => '<html><body><h1>404 Not Found</h1></body></html>',
        ];
        $this->http412 = [
            'status' => Status::PRECONDITION_FAILED
        ];
        $this->http405 = [
            'status' => Status::METHOD_NOT_ALLOWED,
            'header' => ['Allow: GET, HEAD, OPTIONS'],
        ];
    }

    private function initRoot($rootPath) {
        $rootPath = str_replace('\\', '/', $rootPath);
        if (!(is_readable($rootPath) && is_dir($rootPath))) {
            throw new \InvalidArgumentException(
                'Document root must be a readable directory'
            );
        }
        $this->rootPath = rtrim($rootPath, '/');
    }

    private function initMime($mimePath) {
        $mimePath = str_replace('\\', '/', $mimePath);
        $mimeStr = @file_get_contents($mimePath);
        if ($mimeStr === false) {
            throw new \InvalidArgumentException(
                sprintf('Failed loading mime types from file: %s', $mimePath)
            );
        }

        if (preg_match_all("#\s*([a-z0-9]+)\s+(.+)#i", $mimeStr, $matches)) {
            foreach ($matches[1] as $key => $value) {
                $this->mimeTypes[strtolower($value)] = $matches[2][$key];
            }
        }
    }

    public function __invoke(array $request) {
        $path = $request['REQUEST_URI_PATH'];
        $statStruct = isset($this->cache[$path])
            ? $this->cache[$path]
            : $this->queryFilesystem($path);

        return ($statStruct === false)
            ? $this->http404
            : $this->respond($request, $statStruct);
    }

    private function queryFilesystem($path) {
        // NOTE: input $path always contains a leading slash from the request array
        $path = realpath($this->rootPath . $path);

        // If the path doesn't exist we're finished here
        if ($path === false) {
            return false;
        }

        // Windows borks without this
        $path = str_replace('\\', '/', $path);

        // Protect against dot segment path traversal above the document root
        if (strpos($path, $this->rootPath) !== 0) {
            return false;
        }

        // Look for index filename matches if this is a directory path
        if (is_dir($path) && $this->indexes) {
            $path = $this->coalesceIndexPath($path, $this->indexes);
        }

        $stat = stat($path);
        $inode = $stat[1];
        $size = $stat[7];
        $mtime = $stat[9];
        $etag = $this->etagFlags ? $this->generateEtag($path . $mtime, $size, $inode) : null;

        if ($size > $this->maxCacheEntrySize) {
            $cache = fopen($path, 'r');
            $cacheType = self::$CACHE_HANDLE;
        } else {
            $cache = file_get_contents($path);
            $cacheType = self::$CACHE_BUFFER;
        }

        if ($cache === false) {
            throw new \RuntimeException(
                sprintf('Failed loading file: %s', $coalescedPath)
            );
        }

        $statStruct = [$path, $size, $mtime, $etag, $cache, $cacheType];

        clearstatcache(true, $path);

        if ($this->enableCache) {
            $this->cacheStatStruct($path, $statStruct);
        }

        return $statStruct;
    }

    private function coalesceIndexPath($path, $indexes) {
        $dir = rtrim($path, '/');
        foreach ($indexes as $filename) {
            $coalescedPath = $dir . '/' . $filename;
            if (file_exists($coalescedPath)) {
                clearstatcache(true, $coalescedPath);
                return $coalescedPath;
            }
        }

        return $path;
    }

    private function generateEtag($etagBase, $size, $inode) {
        if ($this->etagFlags & self::ETAG_SIZE) {
            $etagBase .= $size;
        }

        if ($this->etagFlags & self::ETAG_INODE) {
            $etagBase .= $inode;
        }

        return md5($etagBase);
    }

    private function cacheStatStruct($path, $statStruct) {
        // It's important to use unset() here because we need to
        // place the expiration timeout at the back of the queue.
        unset($this->cacheTimeouts[$path]);

        if ($this->enableCache && !$this->isCacheWatcherEnabled) {
            $this->now = time();
            $this->reactor->enable($this->cacheCollectWatcher);
            $this->isCacheWatcherEnabled = true;
        }

        $this->cacheTimeouts[$path] = $this->now + $this->cacheTtl;
        $this->cache[$path] = $statStruct;

        // Remove the oldest cache entry if we've reached the max number of entries
        if ($this->cacheCount++ === $this->maxCacheEntries) {
            reset($this->cache);
            $key = key($this->cache);
            unset($this->cacheTimeouts[$key]);
        }
    }

    private function respond(array $request, array $statStruct) {
        $method = $request['REQUEST_METHOD'];

        if ($method === 'OPTIONS') {
            return [
                'status' => Status::OK,
                'header' => [
                    'Allow: GET, HEAD, OPTIONS',
                    //'Accept-Ranges: bytes', // @TODO Re-enable once range responses are fixed
                ]
            ];
        }

        if (!($method === 'GET' || $method === 'HEAD')) {
            return $this->http405;
        }

        $ranges = empty($request['HTTP_RANGE']) ? null : $request['HTTP_RANGE'];
        $sendRangeResponse = (bool) $ranges;

        list($path, $size, $mtime, $etag) = $statStruct;

        switch ($this->checkPreconditions($request, $mtime, $size, $etag)) {
            case self::$PRE_NOT_MODIFIED:
                return $this->makeNotModifiedResponse($etag, $mtime);
            case self::$PRE_FAILED:
                return $this->http412;
            case self::$PRE_IF_RANGE_OK:
                $sendRangeResponse = true;
                break;
            case self::$PRE_IF_RANGE_FAILED:
                $sendRangeResponse = false;
                break;
        }

        /*
        // $protocol = $request['SERVER_PROTOCOL'];
        // @TODO Restore this once range responses are fixed
        return $sendRangeResponse
            ? $this->makeRangeResponseWriter($statStruct, $proto, $ranges)
            : $this->makeResponseWriter($statStruct, $proto);
        */

        return $this->makeResponder($statStruct);
    }

    private function checkPreconditions($request, $mtime, $size, $etag) {
        $ifMatchHeader = !empty($request['HTTP_IF_MATCH'])
            ? $request['HTTP_IF_MATCH']
            : null;

        if ($ifMatchHeader && !$this->etagMatchesPrecondition($etag, $ifMatchHeader)) {
            return self::$PRE_FAILED;
        }

        $ifNoneMatchHeader = !empty($request['HTTP_IF_NONE_MATCH'])
            ? $request['HTTP_IF_NONE_MATCH']
            : null;

        if ($ifNoneMatchHeader && $this->etagMatchesPrecondition($etag, $ifNoneMatchHeader)) {
            return self::$PRE_NOT_MODIFIED;
        }

        $ifModifiedSinceHeader = !empty($request['HTTP_IF_MODIFIED_SINCE'])
            ? @strtotime($request['HTTP_IF_MODIFIED_SINCE'])
            : null;

        if ($ifModifiedSinceHeader && $ifModifiedSinceHeader <= $mtime) {
            return self::$PRE_NOT_MODIFIED;
        }

        $ifUnmodifiedSinceHeader = !empty($request['HTTP_IF_UNMODIFIED_SINCE'])
            ? @strtotime($request['HTTP_IF_UNMODIFIED_SINCE'])
            : null;

        if ($ifUnmodifiedSinceHeader && $mtime > $ifUnmodifiedSinceHeader) {
            return self::$PRE_FAILED;
        }

        $ifRangeHeader = !empty($request['HTTP_IF_RANGE'])
            ? $request['HTTP_IF_RANGE']
            : null;

        if ($ifRangeHeader) {
            return $this->ifRangeMatchesPrecondition($etag, $mtime, $ifRangeHeader)
                ? self::$PRE_IF_RANGE_OK
                : self::$PRE_IF_RANGE_FAILED;
        }

        return self::$PRE_PASS;
    }

    private function etagMatchesPrecondition($etag, $headerStringOrArray) {
        $etagArr = is_string($headerStringOrArray)
            ? explode(',', $headerStringOrArray)
            : $headerStringOrArray;

        foreach ($etagArr as $value) {
            if ($etag == $value) {
                return true;
            }
        }

        return false;
    }

    private function ifRangeMatchesPrecondition($etag, $mtime, $ifRangeHeader) {
        return ($httpDate = @strtotime($ifRangeHeader))
            ? ($mtime <= $httpDate)
            : ($etag === $ifRangeHeader);
    }

    private function makeResponder($statStruct) {
        list($path, $size, $mtime, $etag, $cache, $cacheType) = $statStruct;

        $header = $this->buildCommonHeaders($mtime, $etag);
        $header[] = $this->generateContentTypeHeader($path);

        return ($cacheType === self::$CACHE_BUFFER)
            ? ['header' => $header, 'body' => $cache]
            : new NaiveRootStreamResponder($cache, $header, $size);
    }

    private function buildCommonHeaders($mtime, $etag) {
        $headers = [
            'Cache-Control: public',
            'Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' UTC',
            // 'Accept-Ranges: bytes' // @TODO Restore this after range support is fixed
        ];

        if ($this->expiresPeriod > 0) {
            $expiry =  time() + $this->expiresPeriod;
            $headers[] = 'Expires: ' . gmdate('D, d M Y H:i:s', $expiry) . ' UTC';
        } else {
            $headers[] = 'Expire: 0';
        }

        if ($etag) {
            $headers[] = "Etag: {$etag}";
        }

        return $headers;
    }

    private function generateContentTypeHeader($path) {
        $mime = $this->getMimeTypeFromPath($path) ?: $this->defaultMimeType;

        if (stripos($mime, 'text/') === 0) {
            $mime .= '; charset=' . $this->defaultCharset;
        }

        return "Content-Type: {$mime}";
    }

    private function getMimeTypeFromPath($path) {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        if ($ext == '') {
            return null;
        }
        $ext = strtolower($ext);

        return isset($this->mimeTypes[$ext]) ? $this->mimeTypes[$ext] : 'application/octet-stream';
    }

    private function makeRangeResponse($statStruc, $proto, $ranges) {
        list($path, $size, $mtime, $etag, $buffer) = $statStruct;

        $ranges = $this->normalizeByteRanges($size, $ranges);
        if (empty($ranges)) {
            return [
                'status' => Status::REQUESTED_RANGE_NOT_SATISFIABLE,
                'header' => ["Content-Range: */{$size}"]
            ];
        }

        $isMultipart = isset($ranges[1]);

        if ($isMultipart) {
            $mime = $this->getMimeTypeFromPath($path);
            $headers = $this->buildMultipartRangeHeaders($mime, $ranges, $mtime, $size, $etag);
            $body = new ThreadMultiRangeBody($this->dispatcher, $path, $size, $ranges, $boundary, $type);
        } else {
            $headers = $this->buildRangeHeaders($path, $ranges, $mtime, $size, $etag);
            list($startPos, $endPos) = $ranges[0];
            $size = $endPos - $startPos;
            $body = new ThreadBodyRange($this->dispatcher, $path, $size, $startPos);
        }

        return [
            'status' => Status::PARTIAL_CONTENT,
            'header' => $headers,
            'body'   => $body,
        ];
    }

    private function buildRangeHeaders($path, $ranges, $mtime, $size, $etag) {
        $headers = $this->buildCommonHeaders($mtime, $etag);

        list($startPos, $endPos) = $ranges[0];

        $headers[] = 'Content-Length: ' . ($endPos - $startPos);
        $headers[] = "Content-Range: bytes {$startPos}-{$endPos}/{$size}";
        $headers[] = 'Content-Type: ' . $this->generateContentTypeHeader($path);

        return $headers;
    }

    private function buildMultipartRangeHeaders($mime, $ranges, $mtime, $size, $etag) {
        $headers = $this->buildCommonHeaders($mtime, $etag);
        $contentLength = $this->calculateMultipartLength($mime, $ranges, $size);
        $headers[] = "Content-Length: {$contentLength}";
        $headers[] = "Content-Type:  multipart/byteranges; boundary={$this->multipartBoundary}";

        return $headers;
    }

    private function calculateMultipartLength($mime, $ranges, $size) {
        $totalSize = 0;
        $templateSize = strlen($this->multipartTemplate) - 10; // Don't count sprintf format strings
        $boundarySize = strlen($this->multipartBoundary);
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
            if ($startPos >= $size || $endPos <= $startPos || $endPos <= 0) {
                return null;
            }

            $ranges[] = [$startPos, $endPos];
        }

        return $ranges;
    }

    private function makeNotModifiedResponse($etag, $mtime) {
        $response = [
            'status' => Status::NOT_MODIFIED,
            'header' => ['Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' UTC'],
        ];

        if ($etag) {
            $response['header'][] = "ETag: {$etag}";
        }

        return $response;
    }

    /**
     * Set multiple DocRoot options
     *
     * @param array $options Key-value array mapping option name keys to values
     * @return self
     */
    public function setAllOptions(array $options) {
        foreach ($options as $option => $value) {
            $this->setOption($option, $value);
        }

        return $this;
    }

    /**
     * Set document root responder options
     *
     * @param string $option The option key (case-insensitve)
     * @param mixed $value The option value to assign
     * @throws \DomainException On unrecognized option key
     * @return self
     */
    public function setOption($option, $value) {
        switch(strtolower($option)) {
            case self::OP_INDEXES:
                $this->setIndexes($value);
                break;
            case self::OP_ETAG_FLAGS:
                $this->setEtagFlags($value);
                break;
            case self::OP_EXPIRES_PERIOD:
                $this->setExpiresPeriod($value);
                break;
            case self::OP_DEFAULT_MIME:
                $this->setDefaultMimeType($value);
                break;
            case self::OP_DEFAULT_CHARSET:
                $this->setDefaultTextCharset($value);
                break;
            case self::OP_ENABLE_CACHE:
                $this->setEnableCache($value);
                break;
            case self::OP_CACHE_TTL:
                $this->setCacheTtl($value);
                break;
            case self::OP_CACHE_MAX_ENTRIES:
                $this->setMaxCacheEntries($value);
                break;
            case self::OP_CACHE_MAX_ENTRY_SIZE:
                $this->setMaxCacheEntrySize($value);
                break;
            default:
                throw new \DomainException(
                    "Unknown DocRoot option: {$option}"
                );
        }

        return $this;
    }

    private function setEnableCache($boolFlag) {
        $this->enableCache = (bool) $boolFlag;
    }

    private function setIndexes(array $indexes) {
        $this->indexes = $indexes;
    }

    private function setEtagFlags($mode) {
        $this->etagFlags = (int) $mode;
    }

    private function setExpiresPeriod($seconds) {
        $seconds = (int) $seconds;
        if ($seconds < -1) {
            $seconds = -1;
        }
        $this->expiresPeriod = $seconds;
    }

    private function setDefaultMimeType($mimeType) {
        $this->defaultMimeType = $mimeType;
    }

    private function setCustomMimeTypes(array $mimeTypes) {
        foreach ($mimeTypes as $ext => $type) {
            $ext = strtolower(ltrim($ext, '.'));
            $this->customMimeTypes[$ext] = $type;
        }
    }

    private function setDefaultTextCharset($charset) {
        $this->defaultCharset = $charset;
    }

    private function setCacheTtl($seconds) {
        $seconds = (int) $seconds;
        if ($seconds < 0) {
            $seconds = 30;
        }
        $this->maxCacheAge = $seconds;
    }

    private function setMaxCacheEntries($count) {
        $count = (int) $count;
        if ($count < 0) {
            $count = 50;
        }
        $this->maxCacheEntries = $count;
    }

    private function setMaxCacheEntrySize($bytes) {
        $bytes = (int) $bytes;
        if ($bytes < 0) {
            $bytes = 50;
        }
        $this->maxCacheEntrySize = $bytes;
    }

    /**
     * Receive notifications from the server when it starts/stops
     *
     * @param \Aerys\Server $server
     * @param int $event
     * @return \Amp\Promise
     */
    public function onServerUpdate(Server $server, $event) {
        if ($event === Server::STOPPING) {
            $this->stop();
        }

        return new Success;
    }

    public function stop() {
        $this->reactor->cancel($this->cacheCollectWatcher);
        $this->cache = [];
        $this->cacheTimeouts = [];
        $this->cacheCount = 0;
    }

    private function collectStaleCacheEntries() {
        $this->now = $now = time();
        foreach ($this->cacheTimeouts as $path => $expiresAt) {
            if ($now > $expiresAt) {
                $this->cacheCount--;
                unset(
                $this->cache[$path],
                $this->cacheTimeouts[$path]
                );
            } else {
                break;
            }
        }

        if ($this->cacheCount === 0) {
            $this->isCacheWatcherEnabled = false;
            $this->reactor->disable($this->cacheCollectWatcher);
        }
    }

    public function __destruct() {
        $this->reactor->cancel($this->cacheCollectWatcher);
    }
}

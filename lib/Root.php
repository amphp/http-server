<?php

namespace Aerys;

use Amp as amp;
use Amp\File as file;
use Amp\Loop;

class Root implements ServerObserver {
    const PRECOND_NOT_MODIFIED = 1;
    const PRECOND_FAILED = 2;
    const PRECOND_IF_RANGE_OK = 3;
    const PRECOND_IF_RANGE_FAILED = 4;
    const PRECOND_OK = 5;

    private $root;
    private $debug;
    private $filesystem;
    private $multipartBoundary;
    private $cache = [];
    private $cacheTimeouts = [];
    private $cacheWatcher;
    private $now;

    private $mimeTypes = [];
    private $mimeFileTypes = [];
    private $indexes = ["index.html", "index.htm"];
    private $useEtagInode = true;
    private $expiresPeriod = 86400 * 7;
    private $defaultMimeType = "text/plain";
    private $defaultCharset = "utf-8";
    private $useAggressiveCacheHeaders = false;
    private $aggressiveCacheMultiplier = 0.9;
    private $cacheEntryTtl = 10;
    private $cacheEntryCount = 0;
    private $cacheEntryMaxCount = 2048;
    private $bufferedFileCount = 0;
    private $bufferedFileMaxCount = 50;
    private $bufferedFileMaxSize = 524288;

    /**
     * @param string $root Document root
     * @param \Amp\File\Driver $filesystem Optional filesystem driver
     * @throws \Error On invalid root path
     */
    public function __construct(string $root, file\Driver $filesystem = null) {
        $root = \str_replace("\\", "/", $root);
        if (!(\is_readable($root) && \is_dir($root))) {
            throw new \Error(
                "Document root requires a readable directory"
            );
        }
        $this->root = \rtrim(\realpath($root), "/");
        $this->filesystem = $filesystem ?: file\filesystem();
        $this->multipartBoundary = \uniqid("", true);
        $this->cacheWatcher = Loop::repeat(1000, function() {
            $this->now = $now = time();
            foreach ($this->cacheTimeouts as $path => $timeout) {
                if ($now <= $timeout) {
                    break;
                }
                $fileInfo = $this->cache[$path];
                unset(
                    $this->cache[$path],
                    $this->cacheTimeouts[$path]
                );
                $this->bufferedFileCount -= isset($fileInfo->buffer);
                $this->cacheEntryCount--;
            }
        });
        if($this->cacheWatcher) {
            Loop::disable($this->cacheWatcher);
        }
    }

    /**
     * Respond to HTTP requests for filesystem resources
     */
    public function __invoke(Request $request, Response $response) {
        $uri = $request->getLocalVar("aerys.sendfile") ?: $request->getUri();
        $path = ($qPos = \strpos($uri, "?")) ? \substr($uri, 0, $qPos) : $uri;
        // IMPORTANT! Do NOT remove this. If this is left in, we'll be able to use /path\..\../outsideDocRoot defeating the removeDotPathSegments() function! (on Windows at least)
        $path = \str_replace("\\", "/", $path);
        $path = self::removeDotPathSegments($path);

        // We specifically break the lookup generator out into its own method
        // so that we can potentially avoid forcing the server to resolve a
        // coroutine when the file is already cached.
        return ($fileInfo = $this->fetchCachedStat($path, $request))
            ? $this->respond($fileInfo, $request, $response)
            : $this->respondWithLookup($this->root . $path, $path, $request, $response);
    }

    /**
     * Normalize paths with relative dot segments in their path
     *
     * This functionality is critical to avoid malicious URIs attempting to
     * traverse the document root above the allowed base path.
     *
     * @param string $path
     * @return string
     */
    public static function removeDotPathSegments(string $path): string {
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
                while (array_pop($outputStack) === "/");
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

    private function fetchCachedStat(string $reqPath, Request $request) {
        // We specifically allow users to bypass cached representations in debug mode by
        // using their browser's "force refresh" functionality. This lets us avoid the
        // annoyance of stale file representations being served for a few seconds after
        // changes have been written to disk.
        if (empty($this->debug)) {
            return $this->cache[$reqPath] ?? null;
        }

        foreach ($request->getHeaderArray("Cache-Control") as $value) {
            if (strcasecmp($value, "no-cache") === 0) {
                return null;
            }
        }

        foreach ($request->getHeaderArray("Pragma") as $value) {
            if (strcasecmp($value, "no-cache") === 0) {
                return null;
            }
        }

        return $this->cache[$reqPath] ?? null;
    }

    private function shouldBufferContent($fileInfo) {
        if ($fileInfo->size > $this->bufferedFileMaxSize) {
            return false;
        }
        if ($this->bufferedFileCount >= $this->bufferedFileMaxCount) {
            return false;
        }
        if ($this->cacheEntryCount >= $this->cacheEntryMaxCount) {
            return false;
        }

        return true;
    }

    private function respondWithLookup(string $realPath, string $reqPath, Request $request, Response $response): \Generator {
        // We don't catch any potential exceptions from this yield because they represent
        // a legitimate error from some sort of disk failure. Just let them bubble up to
        // the server where they'll turn into a 500 response.
        $fileInfo = yield from $this->lookup($realPath);

        // Specifically use the request path to reference this file in the
        // cache because the file entry path may differ if it's reflecting
        // a directory index file.
        if ($this->cacheEntryCount < $this->cacheEntryMaxCount) {
            $this->cacheEntryCount++;
            $this->cache[$reqPath] = $fileInfo;
            $this->cacheTimeouts[$reqPath] = $this->now + $this->cacheEntryTtl;
        }

        $result = $this->respond($fileInfo, $request, $response);
        if ($result instanceof \Generator) {
            yield from $result;
        }
    }

    private function lookup(string $path): \Generator {
        $fileInfo = new class {
            use amp\Struct;
            public $exists;
            public $path;
            public $size;
            public $mtime;
            public $inode;
            public $buffer;
            public $etag;
            public $handle;
        };

        $fileInfo->exists = false;
        $fileInfo->path = $path;

        if (!$stat = yield $this->filesystem->stat($path)) {
            return $fileInfo;
        }

        if (yield $this->filesystem->isdir($path)) {
            if ($indexPathArr = yield from $this->coalesceIndexPath($path)) {
                list($fileInfo->path, $stat) = $indexPathArr;
            } else {
                return $fileInfo;
            }
        }

        $fileInfo->exists = true;
        $fileInfo->size = (int) $stat["size"];
        $fileInfo->mtime = $stat["mtime"] ?? 0;
        $fileInfo->inode = $stat["ino"] ?? 0;
        $inode = $this->useEtagInode ? $fileInfo->inode : "";
        $fileInfo->etag = \md5("{$fileInfo->path}{$fileInfo->mtime}{$fileInfo->size}{$inode}");

        if ($this->shouldBufferContent($fileInfo)) {
            $fileInfo->buffer = yield $this->filesystem->get($fileInfo->path);
            $this->bufferedFileCount++;
        }

        return $fileInfo;
    }

    private function coalesceIndexPath(string $dirPath): \Generator {
        $dirPath = \rtrim($dirPath, "/") . "/";
        foreach ($this->indexes as $indexFile) {
            $coalescedPath = $dirPath . $indexFile;
            if (yield $this->filesystem->isfile($coalescedPath)) {
                $stat = yield $this->filesystem->stat($coalescedPath);
                return [$coalescedPath, $stat];
            }
        }
    }

    private function respond($fileInfo, Request $request, Response $response) {
        // If the file doesn't exist don't bother to do anything else so the
        // HTTP server can send a 404 and/or allow handlers further down the chain
        // a chance to respond.
        if (empty($fileInfo->exists)) {
            return;
        }

        switch ($request->getMethod()) {
            case "GET":
            case "HEAD":
                break;
            case "OPTIONS":
                $response->setStatus(HTTP_STATUS["OK"]);
                $response->setHeader("Allow", "GET, HEAD, OPTIONS");
                $response->setHeader("Accept-Ranges", "bytes");
                $response->stream(makeGenericBody(HTTP_STATUS["OK"]));
                return;
            default:
                $response->setStatus(HTTP_STATUS["METHOD_NOT_ALLOWED"]);
                $response->setHeader("Allow", "GET, HEAD, OPTIONS");
                $response->stream(makeGenericBody(HTTP_STATUS["METHOD_NOT_ALLOWED"]));
                return;
        }

        $precondition = $this->checkPreconditions($request, $fileInfo->mtime, $fileInfo->etag);

        switch ($precondition) {
            case self::PRECOND_NOT_MODIFIED:
                $response->setStatus(HTTP_STATUS["NOT_MODIFIED"]);
                $lastModifiedHttpDate = \gmdate('D, d M Y H:i:s', $fileInfo->mtime) . " GMT";
                $response->setHeader("Last-Modified", $lastModifiedHttpDate);
                if ($fileInfo->etag) {
                    $response->setHeader("Etag", $fileInfo->etag);
                }
                $response->end();
                return;
            case self::PRECOND_FAILED:
                $response->setStatus(HTTP_STATUS["PRECONDITION_FAILED"]);
                $response->end();
                return;
            case self::PRECOND_IF_RANGE_FAILED:
                // Return this so the resulting generator will be auto-resolved
                return $this->doNonRangeResponse($fileInfo, $response);
        }

        if (!$rangeHeader = $request->getHeader("Range")) {
            // Return this so the resulting generator will be auto-resolved
            return $this->doNonRangeResponse($fileInfo, $response);
        }

        if ($range = $this->normalizeByteRanges($fileInfo->size, $rangeHeader)) {
            // Return this so the resulting generator will be auto-resolved
            return $this->doRangeResponse($range, $fileInfo, $response);
        }

        // If we're still here this is the only remaining response we can send
        $response->setStatus(HTTP_STATUS["REQUESTED_RANGE_NOT_SATISFIABLE"]);
        $response->setHeader("Content-Range", "*/{$fileInfo->size}");
        $response->end();
    }

    private function checkPreconditions(Request $request, int $mtime, string $etag) {
        $ifMatch = $request->getHeader("If-Match");
        if ($ifMatch && \stripos($ifMatch, $etag) === false) {
            return self::PRECOND_FAILED;
        }

        $ifNoneMatch = $request->getHeader("If-None-Match");
        if ($ifNoneMatch && \stripos($ifNoneMatch, $etag) !== false) {
            return self::PRECOND_NOT_MODIFIED;
        }

        $ifModifiedSince = $request->getHeader("If-Modified-Since");
        $ifModifiedSince = $ifModifiedSince ? @\strtotime($ifModifiedSince) : 0;
        if ($ifModifiedSince && $mtime > $ifModifiedSince) {
            return self::PRECOND_NOT_MODIFIED;
        }

        $ifUnmodifiedSince = $request->getHeader("If-Unmodified-Since");
        $ifUnmodifiedSince = $ifUnmodifiedSince ? @\strtotime($ifUnmodifiedSince) : 0;
        if ($ifUnmodifiedSince && $mtime > $ifUnmodifiedSince) {
            return self::PRECOND_FAILED;
        }

        $ifRange = $request->getHeader("If-Range");
        if ($ifRange === null || !$request->getHeader("Range")) {
            return self::PRECOND_OK;
        }

        /**
         * This is a really stupid feature of HTTP but ...
         * If-Range headers may be either an HTTP timestamp or an Etag:
         *
         *     If-Range = "If-Range" ":" ( entity-tag | HTTP-date )
         *
         * @link https://tools.ietf.org/html/rfc7233#section-3.2
         */
        if ($httpDate = @\strtotime($ifRange)) {
            return ($httpDate > $mtime) ? self::PRECOND_IF_RANGE_OK : self::PRECOND_IF_RANGE_FAILED;
        }

        // If the If-Range header was not an HTTP date we assume it's an Etag
        return ($etag === $ifRange) ? self::PRECOND_IF_RANGE_OK : self::PRECOND_IF_RANGE_FAILED;
    }

    private function doNonRangeResponse($fileInfo, Response $response) {
        $this->assignCommonHeaders($fileInfo, $response);
        $response->setHeader("Content-Length",  (string) $fileInfo->size);
        $response->setHeader("Content-Type", $this->selectMimeTypeFromPath($fileInfo->path));

        return isset($fileInfo->buffer)
            ? $response->end($fileInfo->buffer)
            : $this->finalizeResponse($response, $fileInfo);
    }

    private function assignCommonHeaders($fileInfo, Response $response) {
        $response->setHeader("Accept-Ranges", "bytes");
        $response->setHeader("Cache-Control", "public");
        $response->setHeader("Etag", $fileInfo->etag);
        $response->setHeader("Last-Modified", \gmdate('D, d M Y H:i:s', $fileInfo->mtime) . " GMT");

        $canCache = ($this->expiresPeriod > 0);
        if ($canCache && $this->useAggressiveCacheHeaders) {
            $postCheck = (int) ($this->expiresPeriod * $this->aggressiveCacheMultiplier);
            $preCheck = $this->expiresPeriod - $postCheck;
            $expiry = $this->expiresPeriod;
            $value = "post-check={$postCheck}, pre-check={$preCheck}, max-age={$expiry}";
            $response->setHeader("Cache-Control", $value);
        } elseif ($canCache) {
            $expiry =  $this->now + $this->expiresPeriod;
            $response->setHeader("Expires", \gmdate('D, d M Y H:i:s', $expiry) . " GMT");
        } else {
            $response->setHeader("Expires", "0");
        }
    }

    private function selectMimeTypeFromPath(string $path): string {
        $ext = \pathinfo($path, PATHINFO_EXTENSION);
        if (empty($ext)) {
            $mimeType = $this->defaultMimeType;
        } else {
            $ext = \strtolower($ext);
            if (isset($this->mimeTypes[$ext])) {
                $mimeType = $this->mimeTypes[$ext];
            } elseif (isset($this->mimeFileTypes[$ext])) {
                $mimeType = $this->mimeFileTypes[$ext];
            } else {
                $mimeType = $this->defaultMimeType;
            }
        }

        if (\stripos($mimeType, "text/") === 0 && \stripos($mimeType, "charset=") === false) {
            $mimeType .= "; charset={$this->defaultCharset}";
        }

        return $mimeType;
    }

    /**
     * @link https://tools.ietf.org/html/rfc7233#section-2.1
     */
    private function normalizeByteRanges(int $size, string $rawRanges) {
        $rawRanges = \str_ireplace([' ', 'bytes='], '', $rawRanges);
        $rawRanges = explode(',', $rawRanges);

        $ranges = [];

        foreach ($rawRanges as $range) {
            // If a range is missing the dash separator it's malformed; pull out here.
            if (false === strpos($range, '-')) {
                return null;
            }

            list($startPos, $endPos) = explode('-', rtrim($range), 2);

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

        $range = new class {
            use amp\Struct;
            public $ranges;
            public $boundary;
            public $contentType;
        };
        $range->boundary = $this->multipartBoundary;
        $range->ranges = $ranges;

        return $range;
    }

    private function doRangeResponse($range, $fileInfo, Response $response) {
        $this->assignCommonHeaders($fileInfo, $response);
        $range->contentType = $mime = $this->selectMimeTypeFromPath($fileInfo->path);

        if (isset($range->ranges[1])) {
            $response->setHeader("Content-Type", "multipart/byteranges; boundary={$range->boundary}");
        } else {
            list($startPos, $endPos) = $range->ranges[0];
            $response->setHeader("Content-Length", (string) ($endPos - $startPos + 1));
            $response->setHeader("Content-Range", "bytes {$startPos}-{$endPos}/{$fileInfo->size}");
            $response->setHeader("Content-Type", $mime);
        }

        $response->setStatus(HTTP_STATUS["PARTIAL_CONTENT"]);

        return $this->finalizeResponse($response, $fileInfo, $range);
    }

    private function finalizeResponse(Response $response, $fileInfo, $range = null): \Generator {
        $handle = yield $this->filesystem->open($fileInfo->path, "r");

        if (empty($range)) {
            yield from $this->sendNonRange($handle, $response);
        } elseif (empty($range->ranges[1])) {
            list($startPos, $endPos) = $range->ranges[0];
            yield from $this->sendSingleRange($handle, $response, $startPos, $endPos);
        } else {
            yield from $this->sendMultiRange($handle, $response, $fileInfo, $range);
        }
        $response->end();
    }

    private function sendNonRange(file\Handle $handle, Response $response): \Generator {
        while (!$handle->eof()) {
            $chunk = yield $handle->read(8192);
            yield $response->stream($chunk);
        }
    }

    private function sendSingleRange(file\Handle $handle, Response $response, int $startPos, int $endPos): \Generator {
        $bytesRemaining = $endPos - $startPos + 1;
        $handle->seek($startPos);
        while ($bytesRemaining) {
            $toBuffer = ($bytesRemaining > 8192) ? 8192 : $bytesRemaining;
            $chunk = yield $handle->read($toBuffer);
            $bytesRemaining -= \strlen($chunk);
            yield $response->stream($chunk);
        }
    }

    private function sendMultiRange($handle, Response $response, $fileInfo, $range): \Generator {
        foreach ($range->ranges as list($startPos, $endPos)) {
            $header = sprintf(
                "--%s\r\nContent-Type: %s\r\nContent-Range: bytes %d-%d/%d\r\n\r\n",
                $range->boundary,
                $range->contentType,
                $startPos,
                $endPos,
                $fileInfo->size
            );
            yield $response->stream($header);
            yield from $this->sendSingleRange($handle, $response, $startPos, $endPos);
            $response->stream("\r\n");
        }
        $response->stream("--{$range->boundary}--");
    }

    /**
     * Set a document root option
     *
     * @param string $option The option key (case-insensitve)
     * @param mixed $value The option value to assign
     * @throws \Error On unrecognized option key
     */
    public function setOption($option, $value) {
        switch ($option) {
            case "indexes":
                $this->setIndexes($value);
                break;
            case "useEtagInode":
                $this->setUseEtagInode($value);
                break;
            case "expiresPeriod":
                $this->setExpiresPeriod($value);
                break;
            case "mimeFile":
                $this->loadMimeFileTypes($value);
                break;
            case "mimeTypes":
                $this->setMimeTypes($value);
                break;
            case "defaultMimeType":
                $this->setDefaultMimeType($value);
                break;
            case "defaultTextCharset":
                $this->setDefaultTextCharset($value);
                break;
            case "useAggressiveCacheHeaders":
                $this->setUseAggressiveCacheHeaders($value);
                break;
            case "aggressiveCacheMultiplier":
                $this->setAggressiveCacheMultiplier($value);
                break;
            case "cacheEntryTtl":
                $this->setCacheEntryTtl($value);
                break;
            case "cacheEntryMaxCount":
                $this->setCacheEntryMaxCount($value);
                break;
            case "bufferedFileMaxCount":
                $this->setBufferedFileMaxCount($value);
                break;
            case "bufferedFileMaxSize":
                $this->setBufferedFileMaxSize($value);
                break;
            default:
                throw new \Error(
                    "Unknown root option: {$option}"
                );
        }
    }

    private function setIndexes($indexes) {
        if (is_string($indexes)) {
            $indexes = array_map("trim", explode(" ", $indexes));
        } elseif (!is_array($indexes)) {
            throw new \Error(sprintf(
                "Array or string required for root index names: %s provided",
                gettype($indexes)
            ));
        } else {
            foreach ($indexes as $index) {
                if (!is_string($index)) {
                    throw new \Error(sprintf(
                        "Array of string index filenames required: %s provided",
                        gettype($index)
                    ));
                }
            }
        }

        $this->indexes = array_filter($indexes);
    }

    private function setUseEtagInode(bool $useInode) {
        $this->useEtagInode = $useInode;
    }

    private function setExpiresPeriod(int $seconds) {
        $this->expiresPeriod = ($seconds < 0) ? 0 : $seconds;
    }

    private function loadMimeFileTypes(string $mimeFile) {
        $mimeFile = str_replace('\\', '/', $mimeFile);
        $mimeStr = @file_get_contents($mimeFile);
        if ($mimeStr === false) {
            throw new \RuntimeException(
                "Failed loading mime associations from file {$mimeFile}"
            );
        }
        if (!preg_match_all('#\s*([a-z0-9]+)\s+([a-z0-9\-]+/[a-z0-9\-]+(?:\+[a-z0-9\-]+)?)#i', $mimeStr, $matches)) {
            throw new \RuntimeException(
                "No mime associations found in file: {$mimeFile}"
            );
        }
        $mimeTypes = [];
        foreach ($matches[1] as $key => $value) {
            $mimeTypes[strtolower($value)] = $matches[2][$key];
        }

        $this->mimeFileTypes = $mimeTypes;
    }

    private function setMimeTypes(array $mimeTypes) {
        foreach ($mimeTypes as $ext => $type) {
            $ext = strtolower(ltrim($ext, '.'));
            $this->mimeTypes[$ext] = $type;
        }
    }

    private function setDefaultMimeType(string $mimeType) {
        if (empty($mimeType)) {
            throw new \Error(
                'Default mime type expects a non-empty string'
            );
        }

        $this->defaultMimeType = $mimeType;
    }

    private function setDefaultTextCharset(string $charset) {
        if (empty($charset)) {
            throw new \Error(
                'Default charset expects a non-empty string'
            );
        }

        $this->defaultCharset = $charset;
    }

    private function setUseAggressiveCacheHeaders(bool $bool) {
        $this->useAggressiveCacheHeaders = $bool;
    }

    private function setAggressiveCacheMultiplier(float $multiplier) {
        if ($multiplier > 0.00 && $multiplier < 1.0) {
            $this->aggressiveCacheMultiplier = $multiplier;
        } else {
            throw new \Error(
                "Aggressive cache multiplier expects a float < 1; {$multiplier} specified"
            );
        }
    }

    private function setCacheEntryTtl(int $seconds) {
        if ($seconds < 1) {
            $seconds = 10;
        }
        $this->cacheEntryTtl = $seconds;
    }

    private function setCacheEntryMaxCount(int $count) {
        if ($count < 1) {
            $count = 0;
        }
        $this->cacheEntryMaxCount = $count;
    }

    private function setBufferedFileMaxCount(int $count) {
        if ($count < 1) {
            $count = 0;
        }
        $this->bufferedFileMaxCount = $count;
    }

    private function setBufferedFileMaxSize(int $bytes) {
        if ($bytes < 1) {
            $bytes = 524288;
        }
        $this->bufferedFileMaxSize = $bytes;
    }

    /**
     * Receive notifications from the server when it starts/stops
     */
    public function update(Server $server): \Amp\Promise {
        switch ($server->state()) {
            case Server::STARTING:
                $this->loadMimeFileTypes(__DIR__."/../etc/mime");
                break;
            case Server::STARTED:
                $this->debug = $server->getOption("debug");
                Loop::enable($this->cacheWatcher);
                break;
            case Server::STOPPED:
                if($this->cacheWatcher) {
                    Loop::disable($this->cacheWatcher);
                }
                $this->cache = [];
                $this->cacheTimeouts = [];
                $this->cacheEntryCount = 0;
                $this->bufferedFileCount = 0;
                break;
        }

        return new \Amp\Success;
    }
}

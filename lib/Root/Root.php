<?php

namespace Aerys\Root;

use Amp\{
    Reactor,
    Promise,
    Success,
    function reactor
};
use Aerys\{
    Server,
    Request,
    Response,
    ServerObserver,
    const HTTP_STATUS
};

abstract class Root implements ServerObserver {
    const PRECOND_NOT_MODIFIED = 1;
    const PRECOND_FAILED = 2;
    const PRECOND_IF_RANGE_OK = 3;
    const PRECOND_IF_RANGE_FAILED = 4;
    const PRECOND_OK = 5;

    protected $root;
    protected $reactor;
    protected $multipartBoundary;
    protected $cache = [];
    protected $cacheTimeouts = [];
    protected $cacheWatcher;
    protected $now;

    protected $mimeTypes = [];
    protected $mimeFileTypes = [];
    protected $indexes = ["index.html", "index.htm"];
    protected $useEtagInode = true;
    protected $expiresPeriod = 86400 * 7;
    protected $defaultMimeType = "text/plain";
    protected $defaultCharset = "utf-8";
    protected $useAggressiveCacheHeaders = false;
    protected $aggressiveCacheMultiplier = 0.9;
    protected $cacheEntryTtl = 10;
    protected $cacheEntryCount = 0;
    protected $cacheEntryMaxCount = 2048;
    protected $bufferedFileCount = 0;
    protected $bufferedFileMaxCount = 50;
    protected $bufferedFileMaxSize = 524288;
    protected $debug;

    /**
     * Generate a Stat instance from the given path and index file list
     *
     * If the implementation determines the filesystem path to be a directory it
     * is responsible for iterating over $this->index to attempt a match.
     *
     * Implementations must resolve the returned promise to a Stat instance. If
     * the requested file is not found the Stat::$exists property must be set
     * to a falsy value -- otherwise it must be intialized to true.
     *
     * If the resulting stat's file size is less than than the allowed buffer entry
     * size the implementation should buffer it in $stat->buffer prior to resolving
     * the returned promise.
     *
     * @param string $path The path for which to generate a file entry
     * @param array $indexes An array of possible directory index file names
     * @return \Amp\Promise
     */
    abstract protected function stat(string $path): \Generator;

    /**
     * Send the response entity body
     *
     * If the $range parameter is non-null implementations must send a range
     * response appropriate to its contents.
     *
     * @param \Aerys\Response $response
     * @param \Aerys\Root\Stat $stat
     * @param \Aerys\Root\Range $range
     */
    abstract protected function respond(Response $response, Stat $stat, Range $range = null);

    /**
     * @param string $root
     * @param \Amp\Reactor $reactor
     */
    public function __construct(string $root, Reactor $reactor = null) {
        $root = str_replace("\\", "/", $root);
        if (!(is_readable($root) && is_dir($root))) {
            throw new \InvalidArgumentException(
                "Document root requires a readable directory: {$root}"
            );
        }
        $this->root = rtrim(realpath($root), "/");
        $this->reactor = $reactor ?: reactor();
        $this->multipartBoundary = uniqid('', true);
        $this->cacheWatcher = $this->reactor->repeat(function() {
            $this->now = $now = time();
            foreach ($this->cacheTimeouts as $path => $timeout) {
                if ($now <= $timeout) {
                    break;
                }
                $stat = $this->cache[$path];
                unset(
                    $this->cache[$path],
                    $this->cacheTimeouts[$path]
                );
                $this->bufferedFileCount -= isset($stat->buffer);
                $this->cacheEntryCount--;
            }
        }, 1000, $options = ["enable" => false]);
    }

    /**
     * Respond to HTTP requests for filesystem resources
     *
     * @param \Aerys\Request $request
     * @return mixed
     */
    public function __invoke(Request $request, Response $response) {
        $uri = $request->getUri();
        $path = ($qPos = stripos($uri, "?")) ? substr($uri, 0, $qPos) : $uri;
        $path = $reqPath = str_replace("\\", "/", $path);
        $path = $this->root . $path;
        $path = self::removeDotPathSegments($path);

        // IMPORTANT!
        // Protect against dot segment path traversal above the document root by
        // verifying that the path actually resides in the document root.
        if (strpos($path, $this->root) !== 0) {
            $response->setStatus(HTTP_STATUS["FORBIDDEN"]);
            $response->setHeader("Aerys-Generic-Response", "enable");
            $response->end();
        }

        // We specifically break the lookup generator out into its own method
        // so that we can potentially avoid forcing the server to resolve a
        // coroutine when the file is already cached.
        return ($stat = $this->fetchCachedStat($reqPath, $request))
            ? $this->doResponse($stat, $request, $response)
            : $this->doResponseWithStat($path, $reqPath, $request, $response);
    }

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

    protected function fetchCachedStat(string $reqPath, Request $request) {
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

    protected function shouldBufferContent(Stat $stat) {
        if ($stat->size > $this->bufferedFileMaxSize) {
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

    protected function doResponseWithStat(string $realPath, string $reqPath, Request $request, Response $response): \Generator {
        // We don't catch any potential exceptions from this yield because they represent
        // a legitimate error from some sort of disk failure. Just let them bubble up to
        // the server where they'll turn into a 500 response.
        $stat = yield from $this->stat($realPath);

        // Specifically use the request path to reference this file in the
        // cache because the file entry path may differ if it's reflecting
        // a directory index file.
        if ($this->cacheEntryCount < $this->cacheEntryMaxCount) {
            $this->cacheEntryCount++;
            $this->cache[$reqPath] = $stat;
            $this->cacheTimeouts[$reqPath] = $this->now + $this->cacheEntryTtl;
        }

        $result = $this->doResponse($stat, $request, $response);
        if ($result instanceof \Generator) {
            yield from $result;
        }
    }

    protected function doResponse(Stat $stat, Request $request, Response $response) {
        // If the file doesn't exist don't bother to do anything else so the
        // HTTP server can send a 404 and/or allow handlers further down the chain
        // a chance to respond.
        if (empty($stat->exists)) {
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
                $response->setHeader("Aerys-Generic-Response", "enable");
                return;
            default:
                $response->setStatus(HTTP_STATUS["METHOD_NOT_ALLOWED"]);
                $response->setHeader("Allow", "GET, HEAD, OPTIONS");
                $response->setHeader("Aerys-Generic-Response", "enable");
                return;
        }

        $precondition = $this->checkPreconditions($request, $stat->mtime, $stat->etag);

        switch ($precondition) {
            case self::PRECOND_NOT_MODIFIED:
                $response->setStatus(HTTP_STATUS["NOT_MODIFIED"]);
                $lastModifiedHttpDate = gmdate('D, d M Y H:i:s', $stat->mtime) . " GMT";
                $response->setHeader("Last-Modified", $lastModifiedHttpDate);
                if ($stat->etag) {
                    $response->setHeader("Etag", $stat->etag);
                }
                $response->end();
                return;
            case self::PRECOND_FAILED:
                $response->setStatus(HTTP_STATUS["PRECONDITION_FAILED"]);
                $response->end();
                return;
            case self::PRECOND_IF_RANGE_FAILED:
                // Return this so the resulting generator will be auto-resolved
                return $this->doNonRangeResponse($stat, $response);
        }

        if (!$rangeHeader = $request->getHeader("Range")) {
            // Return this so the resulting generator will be auto-resolved
            return $this->doNonRangeResponse($stat, $response);
        }

        if ($range = $this->normalizeByteRanges($stat->size, $rangeHeader)) {
            // Return this so the resulting generator will be auto-resolved
            return $this->doRangeResponse($range, $stat, $response);
        }

        // If we're still here this is the only remaining response we can send
        $response->setStatus(HTTP_STATUS["REQUESTED_RANGE_NOT_SATISFIABLE"]);
        $response->setHeader("Content-Range", "*/{$stat->size}");
        $response->end();
    }

    protected function checkPreconditions(Request $request, int $mtime, string $etag) {
        $ifMatch = $request->getHeader("If-Match");
        if ($ifMatch && stripos($ifMatch, $etag) === false) {
            return self::PRECOND_FAILED;
        }

        $ifNoneMatch = $request->getHeader("If-None-Match");
        if ($ifNoneMatch && stripos($ifNoneMatch, $etag) !== false) {
            return self::PRECOND_NOT_MODIFIED;
        }

        $ifModifiedSince = $request->getHeader("If-Modified-Since");
        $ifModifiedSince = $ifModifiedSince ? @strtotime($ifModifiedSince) : 0;
        if ($ifModifiedSince && $mtime > $ifModifiedSince) {
            return self::PRECOND_NOT_MODIFIED;
        }

        $ifUnmodifiedSince = $request->getHeader("If-Unmodified-Since");
        $ifUnmodifiedSince = $ifUnmodifiedSince ? @strtotime($ifUnmodifiedSince) : 0;
        if ($ifUnmodifiedSince && $mtime > $ifUnmodifiedSince) {
            return self::PRECOND_FAILED;
        }

        $ifRange = $request->getHeader("If-Range");
        if (!($ifRange || $request->getHeader("Range"))) {
            return self::PRECOND_OK;
        }

        /**
         * This is a really stupid feature of HTTP but ...
         * If-Range headers may be either an HTTP timestamp or an Etag:
         *
         *     If-Range = "If-Range" ":" ( entity-tag | HTTP-date )
         *
         * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.27
         */
        if ($httpDate = @strtotime($ifRange)) {
            return ($httpDate > $mtime) ? self::PRECOND_IF_RANGE_OK : self::PRECOND_IF_RANGE_FAILED;
        }

        // If the If-Range header was not an HTTP date we assume it's an Etag
        return ($etag === $ifRange) ? self::PRECOND_IF_RANGE_OK : self::PRECOND_IF_RANGE_FAILED;
    }

    protected function doNonRangeResponse(Stat $stat, Response $response) {
        $this->assignCommonHeaders($stat, $response);
        $response->setHeader("Content-Length",  (string) $stat->size);
        $response->setHeader("Content-Type", $this->selectMimeTypeFromPath($stat->path));

        return isset($stat->buffer)
            ? $response->end($stat->buffer)
            : $this->respond($response, $stat);
    }

    protected function assignCommonHeaders(Stat $stat, Response $response) {
        $response->setHeader("Accept-Ranges", "bytes");
        $response->setHeader("Cache-Control", "public");
        $response->setHeader("Etag", $stat->etag);
        $response->setHeader("Last-Modified", gmdate('D, d M Y H:i:s', $stat->mtime) . " GMT");

        $canCache = ($this->expiresPeriod > 0);
        if ($canCache && $this->useAggressiveCacheHeaders) {
            $postCheck = (int) ($this->expiresPeriod * $this->aggressiveCacheMultiplier);
            $preCheck = $this->expiresPeriod - $postCheck;
            $expiry = $this->expiresPeriod;
            $value = "post-check={$postCheck}, pre-check={$preCheck}, max-age={$expiry}";
            $response->setHeader("Cache-Control", $value);
        } elseif ($canCache) {
            $expiry =  $this->now + $this->expiresPeriod;
            $response->setHeader("Expires", gmdate('D, d M Y H:i:s', $expiry) . " GMT");
        } else {
            $response->setHeader("Expires", "0");
        }
    }

    protected function selectMimeTypeFromPath(string $path): string {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        if (empty($ext)) {
            $mimeType = $this->defaultMimeType;
        } else {
            $ext = strtolower($ext);
            if (isset($this->mimeTypes[$ext])) {
                $mimeType = $this->mimeTypes[$ext];
            } elseif (isset($this->mimeFileTypes[$ext])) {
                $mimeType = $this->mimeFileTypes[$ext];
            } else {
                $mimeType = $this->defaultMimeType;
            }
        }

        if (stripos($mimeType, "text/") === 0 && stripos($mimeType, "charset=") === false) {
            $mimeType .= "; charset={$this->defaultCharset}";
        }

        return $mimeType;
    }

    /**
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.35
     */
    protected function normalizeByteRanges(int $size, string $rawRanges) {
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

        $range = new Range;
        $range->boundary = $this->multipartBoundary;
        $range->ranges = $ranges;

        return $range;
    }

    protected function doRangeResponse(Range $range, Stat $stat, Response $response) {
        $this->assignCommonHeaders($stat);
        $range->contentType = $mime = $this->selectMimeTypeFromPath($stat->path);

        if (isset($range->ranges[1])) {
            $response->setHeader("Content-Type", "multipart/byteranges; boundary={$range->boundary}");
        } else {
            list($startPos, $endPos) = $range->ranges[0];
            $response->setHeader("Content-Length", (string) ($endPos - $startPos));
            $response->setHeader("Content-Range", "bytes {$startPos}-{$endPos}/{$stat->size}");
            $response->setHeader("Content-Type", $mime);
        }

        $response->setStatus(HTTP_STATUS["PARTIAL_CONTENT"]);

        return $this->respond($response, $stat, $range);
    }

    /**
     * Set a document root option
     *
     * @param string $option The option key (case-insensitve)
     * @param mixed $value The option value to assign
     * @throws \DomainException On unrecognized option key
     * @return void
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
                throw new \DomainException(
                    "Unknown root option: {$option}"
                );
        }
    }

    protected function setIndexes($indexes) {
        if (is_string($indexes)) {
            $indexes = array_map("trim", explode(" ", $indexes));
        } elseif (!is_array($indexes)) {
            throw new \DomainException(sprintf(
                "Array or string required for root index names: %s provided",
                gettype($indexes)
            ));
        } else {
            foreach ($indexes as $index) {
                if (!is_string($index)) {
                    throw new \DomainException(sprintf(
                        "Array of string index filenames required: %s provided",
                        gettype($index)
                    ));
                }
            }
        }

        $this->indexes = array_filter($indexes);
    }

    protected function setUseEtagInode(bool $useInode) {
        $this->useEtagInode = $useInode;
    }

    protected function setExpiresPeriod(int $seconds) {
        $this->expiresPeriod = ($seconds < 0) ? 0 : $seconds;
    }

    protected function loadMimeFileTypes(string $mimeFile) {
        $mimeFile = str_replace('\\', '/', $mimeFile);
        $mimeStr = @file_get_contents($mimeFile);
        if ($mimeStr === false) {
            throw new \RuntimeException(
                "Failed loading mime associations from file {$mimeFile}"
            );
        }
        if (!preg_match_all("#\s*([a-z0-9]+)\s+([a-z0-9\-]+/[a-z0-9\-]+)#i", $mimeStr, $matches)) {
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

    protected function setMimeTypes(array $mimeTypes) {
        foreach ($mimeTypes as $ext => $type) {
            $ext = strtolower(ltrim($ext, '.'));
            $this->mimeTypes[$ext] = $type;
        }
    }

    protected function setDefaultMimeType(string $mimeType) {
        if (empty($mimeType)) {
            throw new \InvalidArgumentException(
                'Default mime type expects a non-empty string'
            );
        }

        $this->defaultMimeType = $mimeType;
    }

    protected function setDefaultCharset(string $charset) {
        if (empty($charset)) {
            throw new \InvalidArgumentException(
                'Default charset expects a non-empty string'
            );
        }

        $this->defaultCharset = $charset;
    }

    protected function setUseAggressiveCacheHeaders(bool $bool) {
        $this->useAggressiveCacheHeaders = $bool;
    }

    protected function setAggressiveCacheMultiplier(float $multiplier) {
        if ($multiplier > 0.00 && $multiplier < 1.0) {
            $this->aggressiveCacheMultiplier = $multiplier;
        } else {
            throw new \InvalidArgumentException(
                "Aggressive cache multiplier expects a float < 1; {$multiplier} specified"
            );
        }
    }

    protected function setCacheEntryTtl(int $seconds) {
        if ($seconds < 1) {
            $seconds = 10;
        }
        $this->cacheEntryTtl = $seconds;
    }

    protected function setCacheEntryMaxCount(int $count) {
        if ($count < 1) {
            $count = 0;
        }
        $this->cacheEntryMaxCount = $count;
    }

    protected function setBufferedFileMaxCount(int $count) {
        if ($entries < 1) {
            $entries = 0;
        }
        $this->bufferedFileMaxCount = $entries;
    }

    protected function setBufferedFileMaxSize(int $bytes) {
        if ($bytes < 1) {
            $bytes = 524288;
        }
        $this->bufferedFileMaxSize = $bytes;
    }

    /**
     * Receive notifications from the server when it starts/stops
     *
     * @param \Aerys\Server $server
     * @return \Amp\Promise
     */
    public function update(Server $server): Promise {
        switch ($server->state()) {
            case Server::STARTING:
                $this->debug = $server->getOption("debug");
                break;
            case Server::STARTED:
                $this->reactor->enable($this->cacheWatcher);
                break;
            case Server::STOPPED:
                $this->reactor->disable($this->cacheWatcher);
                $this->cache = [];
                $this->cacheTimeouts = [];
                $this->cacheEntryCount = 0;
                $this->bufferedFileCount = 0;
                break;
        }

        return new Success;
    }
}

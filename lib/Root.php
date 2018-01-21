<?php

namespace Aerys;

use Aerys\Internal\ByteRange;
use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\InputStream;
use Amp\ByteStream\IteratorStream;
use Amp\Coroutine;
use Amp\Emitter;
use Amp\File;
use Amp\Http\Status;
use Amp\Loop;
use Amp\Promise;
use Amp\Struct;
use Amp\Success;
use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\call;

class Root implements Responder, ServerObserver {
    const PRECONDITION_NOT_MODIFIED = 1;
    const PRECONDITION_FAILED = 2;
    const PRECONDITION_IF_RANGE_OK = 3;
    const PRECONDITION_IF_RANGE_FAILED = 4;
    const PRECONDITION_OK = 5;

    const DEFAULT_MIME_TYPE_FILE = __DIR__ . "/../etc/mime";

    /** @var bool */
    private $running = false;

    /** @var \Aerys\ErrorHandler */
    private $errorHandler;

    /** @var \Aerys\Responder|null */
    private $fallback;

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
     * @param string           $root Document root
     * @param \Amp\File\Driver $filesystem Optional filesystem driver
     *
     * @throws \Error On invalid root path
     */
    public function __construct(string $root, File\Driver $filesystem = null) {
        $root = \str_replace("\\", "/", $root);
        if (!(\is_readable($root) && \is_dir($root))) {
            throw new \Error(
                "Document root requires a readable directory"
            );
        }
        $this->root = \rtrim(\realpath($root), "/");
        $this->filesystem = $filesystem ?: File\filesystem();
        $this->multipartBoundary = \uniqid("", true);
        $this->cacheWatcher = Loop::repeat(1000, function () {
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
        Loop::disable($this->cacheWatcher);
    }

    /**
     * Specifies an instance of Responder that is used if no file exists for the requested path.
     * If no fallback is given, a 404 response is returned from respond() when the file does not exist.
     *
     * @param Responder $responder
     *
     * @throws \Error If the server has started.
     */
    public function setFallback(Responder $responder) {
        if ($this->running) {
            throw new \Error("Cannot add fallback responder after the server has started");
        }

        $this->fallback = $responder;
    }

    /**
     * Respond to HTTP requests for filesystem resources.
     */
    public function respond(Request $request): Promise {
        $uri = $request->getUri()->getPath();
        $path = ($qPos = \strpos($uri, "?")) ? \substr($uri, 0, $qPos) : $uri;
        // IMPORTANT! Do NOT remove this. If this is left in, we'll be able to use /path\..\../outsideDocRoot defeating the removeDotPathSegments() function! (on Windows at least)
        $path = \str_replace("\\", "/", $path);
        $path = self::removeDotPathSegments($path);

        // We specifically break the lookup generator out into its own method
        // so that we can potentially avoid forcing the server to resolve a
        // coroutine when the file is already cached.
        return new Coroutine(
            ($fileInfo = $this->fetchCachedStat($path, $request))
                ? $this->respondFromFileInfo($fileInfo, $request)
                : $this->respondWithLookup($this->root . $path, $path, $request)
        );
    }

    /**
     * Normalize paths with relative dot segments in their path.
     *
     * This functionality is critical to avoid malicious URIs attempting to
     * traverse the document root above the allowed base path.
     *
     * @param string $path
     *
     * @return string
     */
    public static function removeDotPathSegments(string $path): string {
        if (strpos($path, '/.') === false) {
            return $path;
        }

        $inputBuffer = $path;
        $outputStack = [];

        /**
         * 2.  While the input buffer is not empty, loop as follows:.
         */
        while ($inputBuffer !== '') {
            /**
             * A.  If the input buffer begins with a prefix of "../" or "./",
             *     then remove that prefix from the input buffer; otherwise,.
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
             *     prefix with "/" in the input buffer; otherwise,.
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
             *     buffer; otherwise,.
             */
            if ($inputBuffer === "/..") {
                array_pop($outputStack);
                $outputStack[] = '/';
                break;
            }
            if (substr($inputBuffer, 0, 4) === "/../") {
                while (array_pop($outputStack) === "/") ;
                $inputBuffer = substr($inputBuffer, 3);
                continue;
            }

            /**
             * D.  if the input buffer consists only of "." or "..", then remove
             *     that from the input buffer; otherwise,.
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
            if (($slashPos = \strpos($inputBuffer, '/', 1)) === false) {
                $outputStack[] = $inputBuffer;
                break;
            }
            $outputStack[] = substr($inputBuffer, 0, $slashPos);
            $inputBuffer = substr($inputBuffer, $slashPos);
        }

        return implode($outputStack);
    }

    private function fetchCachedStat(string $reqPath, Request $request) {
        // We specifically allow users to bypass cached representations in debug mode by
        // using their browser's "force refresh" functionality. This lets us avoid the
        // annoyance of stale file representations being served for a few seconds after
        // changes have been written to disk.
        if (!$this->debug) {
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

    private function shouldBufferContent($fileInfo): bool {
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

    private function respondWithLookup(string $realPath, string $reqPath, Request $request): \Generator {
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

        return yield from $this->respondFromFileInfo($fileInfo, $request);
    }

    private function lookup(string $path): \Generator {
        $fileInfo = new class {
            use Struct;

            public $exists;
            public $path;
            public $size;
            public $mtime;
            public $inode;
            public $buffer;
            public $etag;
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

    private function respondFromFileInfo($fileInfo, Request $request): \Generator {
        if (!$fileInfo->exists) {
            if ($this->fallback !== null) {
                return $this->fallback->respond($request);
            }

            return yield $this->errorHandler->handle(Status::NOT_FOUND, null, $request);
        }

        switch ($request->getMethod()) {
            case "GET":
            case "HEAD":
                break;

            case "OPTIONS":
                return new Response\EmptyResponse(
                    ["Allow" => "GET, HEAD, OPTIONS", "Accept-Ranges" => "bytes"]
                );

            default:
                /** @var \Aerys\Response $response */
                $response = yield $this->errorHandler->handle(Status::METHOD_NOT_ALLOWED, null, $request);
                $response->setHeader("Allow", "GET, HEAD, OPTIONS");
                return $response;
        }

        $precondition = $this->checkPreconditions($request, $fileInfo->mtime, $fileInfo->etag);

        switch ($precondition) {
            case self::PRECONDITION_NOT_MODIFIED:
                $lastModifiedHttpDate = \gmdate('D, d M Y H:i:s', $fileInfo->mtime) . " GMT";
                $response = new Response\EmptyResponse(["Last-Modified" => $lastModifiedHttpDate], Status::NOT_MODIFIED);
                if ($fileInfo->etag) {
                    $response->setHeader("Etag", $fileInfo->etag);
                }
                return $response;

            case self::PRECONDITION_FAILED:
                return yield $this->errorHandler->handle(Status::PRECONDITION_FAILED, null, $request);

            case self::PRECONDITION_IF_RANGE_FAILED:
                // Return this so the resulting generator will be auto-resolved
                return yield from $this->doNonRangeResponse($fileInfo);
        }

        if (!$rangeHeader = $request->getHeader("Range")) {
            // Return this so the resulting generator will be auto-resolved
            return yield from $this->doNonRangeResponse($fileInfo);
        }

        if ($range = $this->normalizeByteRanges($fileInfo->size, $rangeHeader)) {
            // Return this so the resulting generator will be auto-resolved
            return yield from $this->doRangeResponse($range, $fileInfo);
        }

        // If we're still here this is the only remaining response we can send
        /** @var \Aerys\Response $response */
        $response = yield $this->errorHandler->handle(Status::RANGE_NOT_SATISFIABLE, null, $request);
        $response->setHeader("Content-Range", "*/{$fileInfo->size}");
        return $response;
    }

    private function checkPreconditions(Request $request, int $mtime, string $etag): int {
        $ifMatch = $request->getHeader("If-Match");
        if ($ifMatch && \stripos($ifMatch, $etag) === false) {
            return self::PRECONDITION_FAILED;
        }

        $ifNoneMatch = $request->getHeader("If-None-Match");
        if ($ifNoneMatch && \stripos($ifNoneMatch, $etag) !== false) {
            return self::PRECONDITION_NOT_MODIFIED;
        }

        $ifModifiedSince = $request->getHeader("If-Modified-Since");
        $ifModifiedSince = $ifModifiedSince ? @\strtotime($ifModifiedSince) : 0;
        if ($ifModifiedSince && $mtime > $ifModifiedSince) {
            return self::PRECONDITION_NOT_MODIFIED;
        }

        $ifUnmodifiedSince = $request->getHeader("If-Unmodified-Since");
        $ifUnmodifiedSince = $ifUnmodifiedSince ? @\strtotime($ifUnmodifiedSince) : 0;
        if ($ifUnmodifiedSince && $mtime > $ifUnmodifiedSince) {
            return self::PRECONDITION_FAILED;
        }

        $ifRange = $request->getHeader("If-Range");
        if ($ifRange === null || !$request->getHeader("Range")) {
            return self::PRECONDITION_OK;
        }

        /**
         * This is a really stupid feature of HTTP but ...
         * If-Range headers may be either an HTTP timestamp or an Etag:.
         *
         *     If-Range = "If-Range" ":" ( entity-tag | HTTP-date )
         *
         * @link https://tools.ietf.org/html/rfc7233#section-3.2
         */
        if ($httpDate = @\strtotime($ifRange)) {
            return ($httpDate > $mtime) ? self::PRECONDITION_IF_RANGE_OK : self::PRECONDITION_IF_RANGE_FAILED;
        }

        // If the If-Range header was not an HTTP date we assume it's an Etag
        return ($etag === $ifRange) ? self::PRECONDITION_IF_RANGE_OK : self::PRECONDITION_IF_RANGE_FAILED;
    }

    private function doNonRangeResponse($fileInfo): \Generator {
        $headers = $this->makeCommonHeaders($fileInfo);
        $headers["Content-Length"] = (string) $fileInfo->size;
        $headers["Content-Type"] = $this->selectMimeTypeFromPath($fileInfo->path);

        if (isset($fileInfo->buffer)) {
            return new Response(new InMemoryStream($fileInfo->buffer), $headers);
        }

        $handle = yield $this->filesystem->open($fileInfo->path, "r");

        $response = new Response($handle, $headers);
        $response->onDispose([$handle, "close"]);
        return $response;
    }

    private function makeCommonHeaders($fileInfo): array {
        $headers = [
            "Accept-Ranges" => "bytes",
            "Cache-Control" => "public",
            "Etag" => $fileInfo->etag,
            "Last-Modified" => \gmdate('D, d M Y H:i:s', $fileInfo->mtime) . " GMT",
        ];

        $canCache = ($this->expiresPeriod > 0);
        if ($canCache && $this->useAggressiveCacheHeaders) {
            $postCheck = (int) ($this->expiresPeriod * $this->aggressiveCacheMultiplier);
            $preCheck = $this->expiresPeriod - $postCheck;
            $expiry = $this->expiresPeriod;
            $value = "post-check={$postCheck}, pre-check={$preCheck}, max-age={$expiry}";
            $headers["Cache-Control"] = $value;
        } elseif ($canCache) {
            $expiry = $this->now + $this->expiresPeriod;
            $headers["Expires"] = \gmdate('D, d M Y H:i:s', $expiry) . " GMT";
        } else {
            $headers["Expires"] = "0";
        }

        return $headers;
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
     *
     * @param int    $size Total size of the file in bytes.
     * @param string $rawRanges Ranges as provided by the client.
     *
     * @return ByteRange|null
     */
    private function normalizeByteRanges(int $size, string $rawRanges) {
        $rawRanges = \str_ireplace([' ', 'bytes='], '', $rawRanges);

        $ranges = [];

        foreach (\explode(',', $rawRanges) as $range) {
            // If a range is missing the dash separator it's malformed; pull out here.
            if (false === strpos($range, '-')) {
                return null;
            }

            list($startPos, $endPos) = explode('-', rtrim($range), 2);

            if ($startPos === '' && $endPos === '') {
                return null;
            }

            if ($startPos === '' && $endPos !== '') {
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

        $range = new ByteRange;
        $range->boundary = $this->multipartBoundary;
        $range->ranges = $ranges;

        return $range;
    }

    private function doRangeResponse(ByteRange $range, $fileInfo): \Generator {
        $headers = $this->makeCommonHeaders($fileInfo);
        $range->contentType = $mime = $this->selectMimeTypeFromPath($fileInfo->path);

        if (isset($range->ranges[1])) {
            $headers["Content-Type"] = "multipart/byteranges; boundary={$range->boundary}";
        } else {
            list($startPos, $endPos) = $range->ranges[0];
            $headers["Content-Length"] = (string) ($endPos - $startPos + 1);
            $headers["Content-Range"] = "bytes {$startPos}-{$endPos}/{$fileInfo->size}";
            $headers["Content-Type"] = $mime;
        }

        $handle = yield $this->filesystem->open($fileInfo->path, "r");

        if (empty($range->ranges[1])) {
            list($startPos, $endPos) = $range->ranges[0];
            $stream = $this->sendSingleRange($handle, $startPos, $endPos);
        } else {
            $stream = $this->sendMultiRange($handle, $fileInfo, $range);
        }

        $response = new Response($stream, $headers, Status::PARTIAL_CONTENT);
        $response->onDispose([$handle, "close"]);
        return $response;
    }

    private function sendSingleRange(File\Handle $handle, int $startPos, int $endPos): InputStream {
        $emitter = new Emitter;
        $stream = new IteratorStream($emitter->iterate());

        $coroutine = new Coroutine($this->readRangeFromHandle($handle, $emitter, $startPos, $endPos));
        $coroutine->onResolve(function ($error) use ($emitter) {
            if ($error) {
                $emitter->fail($error);
            } else {
                $emitter->complete();
            }
        });

        return $stream;
    }

    private function sendMultiRange($handle, $fileInfo, $range): InputStream {
        $emitter = new Emitter;
        $stream = new IteratorStream($emitter->iterate());

        call(function () use ($handle, $range, $emitter, $fileInfo) {
            foreach ($range->ranges as list($startPos, $endPos)) {
                $header = sprintf(
                    "--%s\r\nContent-Type: %s\r\nContent-Range: bytes %d-%d/%d\r\n\r\n",
                    $range->boundary,
                    $range->contentType,
                    $startPos,
                    $endPos,
                    $fileInfo->size
                );
                yield $emitter->emit($header);
                yield from $this->readRangeFromHandle($handle, $emitter, $startPos, $endPos);
                yield $emitter->emit("\r\n");
            }
            $emitter->emit("--{$range->boundary}--");
        })->onResolve(function ($error) use ($emitter) {
            if ($error) {
                $emitter->fail($error);
            } else {
                $emitter->complete();
            }
        });

        return $stream;
    }

    private function readRangeFromHandle(File\Handle $handle, Emitter $emitter, int $startPos, int $endPos): \Generator {
        $bytesRemaining = $endPos - $startPos + 1;
        yield $handle->seek($startPos);

        while ($bytesRemaining) {
            $toBuffer = ($bytesRemaining > 8192) ? 8192 : $bytesRemaining;
            $chunk = yield $handle->read($toBuffer);
            $bytesRemaining -= \strlen($chunk);
            yield $emitter->emit($chunk);
        }
    }

    /**
     * Set a document root option.
     *
     * @param string $option The option key (case-insensitve)
     * @param mixed  $value The option value to assign
     *
     * @throws \Error On unrecognized option key
     */
    public function setOption(string $option, $value) {
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
        if (\is_string($indexes)) {
            $indexes = array_map("trim", explode(" ", $indexes));
        } elseif (!\is_array($indexes)) {
            throw new \Error(sprintf(
                "Array or string required for root index names: %s provided",
                \gettype($indexes)
            ));
        } else {
            foreach ($indexes as $index) {
                if (!\is_string($index)) {
                    throw new \Error(sprintf(
                        "Array of string index filenames required: %s provided",
                        \gettype($index)
                    ));
                }
            }
        }

        $this->indexes = \array_filter($indexes);
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

        /** @var array[] $matches */
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

    public function onStart(Server $server, PsrLogger $logger, ErrorHandler $errorHandler): Promise {
        $this->running = true;

        if (empty($this->mimeFileTypes)) {
            $this->loadMimeFileTypes(self::DEFAULT_MIME_TYPE_FILE);
        }

        $this->errorHandler = $errorHandler;

        $this->debug = $server->getOptions()->isInDebugMode();
        Loop::enable($this->cacheWatcher);

        if ($this->fallback !== null && $this->fallback instanceof ServerObserver) {
            return $this->fallback->onStart($server, $logger, $errorHandler);
        }

        return new Success;
    }

    public function onStop(Server $server): Promise {
        Loop::disable($this->cacheWatcher);

        $this->cache = [];
        $this->cacheTimeouts = [];
        $this->cacheEntryCount = 0;
        $this->bufferedFileCount = 0;
        $this->running = false;

        if ($this->fallback !== null && $this->fallback instanceof ServerObserver) {
            return $this->fallback->onStop($server);
        }

        return new Success;
    }
}

<?php

namespace Aerys\Handlers\DocRoot;

use Alert\Reactor,
    Aerys\Server,
    Aerys\Status,
    Aerys\Reason,
    Aerys\Writing\ByteRangeBody,
    Aerys\Writing\MultiPartByteRangeBody;

class DocRootHandler {
    
    const ETAG_NONE = 0;
    const ETAG_SIZE = 1;
    const ETAG_INODE = 2;
    const ETAG_ALL = 3;
    
    const PRECONDITION_NOT_MODIFIED = 100;
    const PRECONDITION_FAILED = 200;
    const PRECONDITION_IF_RANGE_OK = 300;
    const PRECONDITION_IF_RANGE_FAILED = 400;
    const PRECONDITION_PASS = 999;
    
    private $reactor;
    private $docRoot;
    private $indexes = ['index.html', 'index.htm'];
    private $eTagMode = self::ETAG_ALL;
    private $expiresHeaderPeriod = 300;
    private $defaultMimeType = 'text/plain';
    private $customMimeTypes = [];
    private $defaultTextCharset = 'utf-8';
    private $indexRedirection = TRUE;
    private $multipartBoundary;
    private $mimeTypes;
    private $cacheTtl = 10;
    private $fileDescriptorCache = [];
    private $memoryCache = [];
    private $memoryCacheMaxSize = 67108864;    // 64 MiB
    private $memoryCacheMaxFileSize = 1048576; //  1 MiB
    private $memoryCacheCurrentSize = 0;
    private $cacheCleanInterval = 1;
    private $cacheWatcher;
    
    function __construct(Reactor $reactor) {
        $this->reactor = $reactor;
        $this->assignDefaultMimeTypes();
        $this->multipartBoundary = uniqid('', TRUE);
        $this->cacheWatcher = $this->reactor->repeat([$this, 'clearStaleCache'], $this->cacheCleanInterval);
    }
    
    /**
     * Set multiple DocRoot options
     * 
     * @param array $options Key-value array mapping option name keys to values
     * @return \Aerys\Handlers\DocRoot\DocRootHandler Returns the current object instance
     */
    function setAllOptions(array $options) {
        foreach ($options as $option => $value) {
            $this->setOption($option, $value);
        }
        
        return $this;
    }
    
    /**
     * Set a DocRoot option
     * 
     * @param string $option The option key (case-insensitve)
     * @param mixed $value The option value to assign
     * @throws \DomainException On unrecognized option key
     * @return \Aerys\Handlers\DocRoot\DocRootHandler Returns the current object instance
     */
    function setOption($option, $value) {
        switch(strtolower($option)) {
            case 'docroot':
                $this->setDocRoot($value);
                break;
            case 'indexes':
                $this->setIndexes($value);
                break;
            case 'indexredirection':
                $this->setIndexRedirection($value);
                break;
            case 'etagmode':
                $this->setETagMode($value);
                break;
            case 'expiresheaderperiod':
                $this->setExpiresHeaderPeriod($value);
                break;
            case 'defaultmimetype':
                $this->setDefaultMimeType($value);
                break;
            case 'custommimetypes':
                $this->setCustomMimeTypes($value);
                break;
            case 'defaulttextcharset':
                $this->setDefaultTextCharset($value);
                break;
            case 'cachettl':
                $this->setCacheTtl($value);
                break;
            case 'memorycachemaxsize':
                $this->setMemoryCacheMaxSize($value);
                break;
            case 'memorycachemaxfilesize':
                $this->setMemoryCacheMaxFileSize($value);
                break;
            default:
                throw new \DomainException(
                    "Unknown DocRoot option: {$option}"
                );
        }
        
        return $this;
    }
    
    private function setDocRoot($path) {
        $path = str_replace('\\', '/', $path);
        
        if (!(is_readable($path) && is_dir($path))) {
            throw new \InvalidArgumentException(
                'Document root must be a readable directory'
            );
        }
        
        $this->docRoot = rtrim($path, '/');
    }
    
    private function setIndexes(array $indexes) {
        $this->indexes = $indexes;
    }
    
    private function setIndexRedirection($boolFlag) {
        $this->indexRedirection = filter_var($boolFlag, FILTER_VALIDATE_BOOLEAN);
    }
    
    private function setETagMode($mode) {
        $this->eTagMode = (int) $mode;
    }
    
    private function setExpiresHeaderPeriod($seconds) {
        $this->expiresHeaderPeriod = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => -1,
            'default' => 300
        ]]);
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
        $this->defaultTextCharset = $charset;
    }
    
    private function setCacheTtl($seconds) {
        $this->cacheTtl = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'default' => 5
        ]]);
    }
    
    private function setMemoryCacheMaxSize($bytes) {
        $this->memoryCacheMaxSize = filter_var($bytes, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'default' => 67108864
        ]]);
    }
    
    private function setMemoryCacheMaxFileSize($bytes) {
        $this->memoryCacheMaxFileSize = filter_var($bytes, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'default' => 1048576
        ]]);
    }
    
    /**
     * Clear all cached file descriptors and memory-mapped files
     * 
     * @return void
     */
    function clearCache() {
        $this->memoryCache = [];
        $this->fileDescriptorCache = [];
        $this->memoryCacheCurrentSize = 0;
        clearstatcache();
    }
    
    /**
     * Clear only stale descriptors and memory-mapped files
     * 
     * @return void
     */
    function clearStaleCache() {
        $now = time();
        $this->clearStaleMemoryCacheEntries($now);
        $this->clearStaleDescriptorCacheEntries($now);
        clearstatcache();
    }
    
    private function clearStaleMemoryCacheEntries($now) {
        foreach ($this->memoryCache as $cacheId => $cacheArr) {
            $cacheExpiry = $cacheArr[1];
            if ($cacheExpiry <= $now) {
                $cacheSizeDecrement = $cacheArr[2];
                $this->memoryCacheSize -= $cacheSizeDecrement;
                unset($this->memoryCache[$cacheId]);
            } else {
                break;
            }
        }
    }
    
    private function clearStaleDescriptorCacheEntries($now) {
        foreach ($this->fileDescriptorCache as $cacheId => $cacheArr) {
            $cacheExpiry = $cacheArr[1];
            if ($cacheExpiry <= $now) {
                unset($this->fileDescriptorCache[$cacheId]);
            } else {
                break;
            }
        }
    }
    
    /**
     * Response to an ASGI request array
     * 
     * @param array $asgiEnv
     * @return array Returns an ASGI response array appropriate to the request
     */
    function __invoke(array $asgiEnv) {
        $requestUri = ltrim($asgiEnv['REQUEST_URI'], '/');
        
        if ($queryStartPos = strpos($requestUri, '?')) {
            $requestUri = substr($requestUri, 0, $queryStartPos);
        }
        
        $filePath = $this->docRoot . '/' . $requestUri;
        
        if (!$filePath = $this->validateFilePath($filePath)) {
            return $this->notFound();
        }
        
        $isDir = is_dir($filePath);
        $redirectToIndex = NULL;
        
        if (!$isDir && $this->indexRedirection && $this->indexes) {
            $pathParts = pathinfo($filePath);
            $redirectToIndex = in_array($pathParts['basename'], $this->indexes)
                ? substr($pathParts['dirname'] . '/', strlen($this->docRoot))
                : NULL;
        }
        
        if ($redirectToIndex) {
            return $this->redirectTo($redirectToIndex);
        } elseif (!$isDir) {
            return $this->respondToFoundFile($filePath, $asgiEnv);
        } elseif ($isDir && $this->indexes && ($filePath = $this->matchIndex($filePath))) {
            return $this->respondToFoundFile($filePath, $asgiEnv);
        } else {
            return $this->notFound();
        }
    }
    
    /**
     * The `realpath()` check is IMPORTANT to prevent access to the filesystem above the defined
     * document root using relative path segments such as "../".
     * 
     * `realpath()` will return FALSE if the file does not exist but we still need to verify that
     * its resulting path resides within the top-level docRoot path if a match is found.
     * 
     * This method carries protected accessibility because vfsStream cannot mock the results of the
     * `realpath()` function and we need to manually use inheritance to mock this behavior in our
     * unit tests. The StaticFileRelativePathAscensionTest integration test validates this security
     * measure against the real file system without vfsStream mocking.
     */
    protected function validateFilePath($filePath) {
        if (!$realPath = realpath($filePath)) {
            return FALSE;
        }
        
        // We have to perform the strpos check to ensure the requested URI is not outside the
        // allowed document root. This causes problems for windows so we normalize all path
        // separators to forward slashes to match the normalization performed on the docRoot.
        $realPath = str_replace('\\', '/', $realPath);
        
        if (0 !== strpos($realPath, $this->docRoot)) {
            return FALSE;
        } else {
            return $realPath;
        }
    }
    
    private function matchIndex($dirPath) {
        foreach ($this->indexes as $indexFile) {
            $indexPath = $dirPath . '/' . $indexFile;
            if (file_exists($indexPath)) {
                return $indexPath;
            }
        }
        
        return NULL;
    }
    
    private function redirectTo($redirectToIndex) {
        $status = Status::MOVED_PERMANENTLY;
        $reason = Reason::HTTP_301;
        $body = '<html><body><h1>Moved!</h1></body></html>';
        $headers = [
            "Location: {$redirectToIndex}",
            'Content-Type: text/html; charset=utf-8',
            'Content-Length: ' . strlen($body),
        ];
        
        return [$status, $reason, $headers, $body];
    }
    
    private function respondToFoundFile($filePath, array $asgiEnv) {
        $method = $asgiEnv['REQUEST_METHOD'];
        
        if ($method == 'OPTIONS') {
            return $this->options();
        } elseif (!($method == 'GET' || $method == 'HEAD')) {
            return $this->methodNotAllowed();
        }
        
        $mTime = filemtime($filePath);
        $fileSize = filesize($filePath);
        $eTag = $this->eTagMode ? $this->getEtag($mTime, $fileSize, $filePath) : NULL;
        
        $ranges = empty($asgiEnv['HTTP_RANGE']) ? NULL : $asgiEnv['HTTP_RANGE'];
        
        switch ($this->checkPreconditions($mTime, $fileSize, $eTag, $asgiEnv)) {
            case self::PRECONDITION_NOT_MODIFIED:
                return $this->notModified($eTag, $mTime);
            case self::PRECONDITION_FAILED:
                return $this->preconditionFailed($eTag, $mTime);
            case self::PRECONDITION_IF_RANGE_OK:
                return $this->doRange($filePath, $method, $ranges, $mTime, $fileSize, $eTag);
            case self::PRECONDITION_IF_RANGE_FAILED:
                return $this->doFile($filePath,  $method, $mTime, $fileSize, $eTag);
            default:
                break;
        }
        
        return $ranges
            ? $this->doRange($filePath, $method, $ranges, $mTime, $fileSize, $eTag)
            : $this->doFile($filePath, $method, $mTime, $fileSize, $eTag);
    }
    
    private function checkPreconditions($mTime, $fileSize, $eTag, $asgiEnv) {
        $ifMatchHeader = !empty($asgiEnv['HTTP_IF_MATCH'])
            ? $asgiEnv['HTTP_IF_MATCH']
            : NULL;
        
        if ($ifMatchHeader && !$this->eTagMatchesPrecondition($eTag, $ifMatchHeader)) {
            return self::PRECONDITION_FAILED;
        }
        
        $ifNoneMatchHeader = !empty($asgiEnv['HTTP_IF_NONE_MATCH'])
            ? $asgiEnv['HTTP_IF_NONE_MATCH']
            : NULL;
        
        if ($ifNoneMatchHeader && $this->eTagMatchesPrecondition($eTag, $ifNoneMatchHeader)) {
            return self::PRECONDITION_NOT_MODIFIED;
        }
        
        $ifModifiedSinceHeader = !empty($asgiEnv['HTTP_IF_MODIFIED_SINCE'])
            ? @strtotime($asgiEnv['HTTP_IF_MODIFIED_SINCE'])
            : NULL;
        
        if ($ifModifiedSinceHeader && $ifModifiedSinceHeader <= $mTime) {
            return self::PRECONDITION_NOT_MODIFIED;
        }
        
        $ifUnmodifiedSinceHeader = !empty($asgiEnv['HTTP_IF_UNMODIFIED_SINCE'])
            ? @strtotime($asgiEnv['HTTP_IF_UNMODIFIED_SINCE'])
            : NULL;
        
        if ($ifUnmodifiedSinceHeader && $mTime > $ifUnmodifiedSinceHeader) {
            return self::PRECONDITION_FAILED;
        }
        
        $ifRangeHeader = !empty($asgiEnv['HTTP_IF_RANGE'])
            ? $asgiEnv['HTTP_IF_RANGE']
            : NULL;
        
        if ($ifRangeHeader) {
            return $this->ifRangeMatchesPrecondition($eTag, $mTime, $ifRangeHeader)
                ? self::PRECONDITION_IF_RANGE_OK
                : self::PRECONDITION_IF_RANGE_FAILED;
        }
        
        return self::PRECONDITION_PASS;
    }
    
    private function eTagMatchesPrecondition($eTag, $headerStringOrArray) {
        $eTagArr = is_string($headerStringOrArray)
            ? explode(',', $headerStringOrArray)
            : $headerStringOrArray;
        
        foreach ($eTagArr as $value) {
            if ($eTag == $value) {
                return TRUE;
            }
        }
        
        return FALSE;
    }
    
    private function ifRangeMatchesPrecondition($eTag, $mTime, $ifRangeHeader) {
        if ($httpDate = @strtotime($ifRangeHeader)) {
            return ($mTime <= $httpDate);
        } else {
            return ($eTag === $ifRangeHeader);
        }
    }
    
    /**
     * @link http://tools.ietf.org/html/rfc2616#section-14.21
     */
    private function doFile($filePath, $method, $mTime, $fileSize, $eTag) {
        $status = Status::OK;
        $reason = Reason::HTTP_200;
        $now = time();
        
        $headers = [
            'Cache-Control: public',
            "Content-Length: {$fileSize}",
            'Last-Modified: ' . gmdate('D, d M Y H:i:s', $mTime) . ' UTC',
            'Accept-Ranges: bytes'
        ];
        
        $headers[] = ($this->expiresHeaderPeriod > 0)
            ? 'Expires: ' . gmdate('D, d M Y H:i:s', $now + $this->expiresHeaderPeriod) . ' UTC'
            : 'Expires: 0';
        
        $headers[] = $this->generateContentTypeHeader($filePath);
        
        if ($eTag) {
            $headers[] = "ETag: {$eTag}";
        }
        
        if ($method !== 'GET') {
            $body = NULL;
        } elseif ($fileSize < $this->memoryCacheMaxFileSize) {
            $body = $this->getMemoryCacheableFile($filePath, $fileSize);
        } elseif ($this->cacheTtl) {
            $body = $this->getCacheableFileDescriptor($filePath);
        } else {
            $body = $this->getFileDescriptor($filePath);
        }
        
        return [$status, $reason, $headers, $body];
    }
    
    private function getMemoryCacheableFile($filePath, $fileSize) {
        $cacheId = strtolower($filePath);
        
        return isset($this->memoryCache[$cacheId])
            ? $this->memoryCache[$cacheId][0]
            : $this->storeFileInMemoryCache($cacheId, $filePath, $fileSize);
    }
    
    private function storeFileInMemoryCache($cacheId, $filePath, $fileSize) {
        $memFile = file_get_contents($filePath);
        $cacheExpiry = time() + $this->cacheTtl;
        $this->memoryCache[$cacheId] = [$memFile, $cacheExpiry, $fileSize, $filePath];
        
        return $memFile;
    }
    
    private function getCacheableFileDescriptor($filePath) {
        $cacheId = strtolower($filePath);
        
        if (isset($this->fileDescriptorCache[$cacheId])) {
            $fd = $this->fileDescriptorCache[$cacheId][0];
        } else {
            $fd = $this->getFileDescriptor($filePath);
            $cacheExpiry = time() + $this->cacheTtl;
            $this->fileDescriptorCache[$cacheId] = [$fd, $cacheExpiry, $filePath];
        }
        
        return $fd;
    }
    
    private function getFileDescriptor($filePath) {
        $fd = fopen($filePath, 'rb');
        stream_set_blocking($fd, 0);
        
        return $fd;
    }
    
    private function generateContentTypeHeader($filePath) {
        $contentType = $this->getMimeType($filePath) ?: $this->defaultMimeType;
        
        if (0 === stripos($contentType, 'text/')) {
            $contentType .= '; charset=' . $this->defaultTextCharset;
        }
        
        return "Content-Type: {$contentType}";
    }
    
    private function doRange($filePath, $method, $ranges, $mTime, $fileSize, $eTag) {
        if (!$ranges = $this->normalizeByteRanges($fileSize, $ranges)) {
           return $this->requestedRangeNotSatisfiable($fileSize);
        }
        
        $now = time();
        $body = $this->getFileDescriptor($filePath);
        $status = Status::PARTIAL_CONTENT;
        $reason = Reason::HTTP_206;
        $headers = [
            'Cache-Control: public',
            'Last-Modified: ' . gmdate('D, d M Y H:i:s', $mTime) . ' UTC'
        ];
        
        if ($this->expiresHeaderPeriod > 0) {
            $time =  $now + $this->expiresHeaderPeriod;
            $headers[] = 'Expires: ' .  gmdate('D, d M Y H:i:s', $time) . ' UTC';
        }
        
        if ($eTag) {
            $headers[] = "ETag: {$eTag}";
        }
        
        $contentType = $this->generateContentTypeHeader($filePath);
        
        if ($isMultiPart = (count($ranges) > 1)) {
            $headers[] = "Content-Type: multipart/byteranges; boundary={$this->multipartBoundary}";
            $body = ($method === 'GET')
                ? new MultiPartByteRangeBody($body, $ranges, $this->multipartBoundary, $contentType, $fileSize)
                : NULL;
            
        } else {
            list($startPos, $endPos) = $ranges[0];
            $headers[] = 'Content-Length: ' . ($endPos - $startPos);
            $headers[] = 'Content-Range: bytes ' . ($startPos - $endPos) . "/{$fileSize}";
            $headers[] = "Content-Type: {$contentType}";
            $body = ($method == 'GET')
                ? new ByteRangeBody($body, $startPos, $endPos)
                : NULL;
        }
        
        return [$status, $reason, $headers, $body];
    }
    
    /**
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.35
     */
    private function normalizeByteRanges($fileSize, $rawRanges) {
        if (is_array($rawRanges)) {
            $rawRanges = implode(',', array_filter($rawRanges));
        }
        
        $rawRanges = str_ireplace([' ', 'bytes='], '', $rawRanges);
        $rawRanges = explode(',', $rawRanges);
        
        $normalizedByteRanges = [];
        
        foreach ($rawRanges as $range) {
            if (FALSE === strpos($range, '-')) {
                return NULL;
            }
            
            list($startPos, $endPos) = explode('-', rtrim($range));
            
            if ($startPos === '' && $endPos === '') {
                return NULL;
            } elseif ($startPos === '' && $endPos !== '') {
                // The -1 is necessary and not a hack because byte ranges are inclusive and start
                // at 0. DO NOT REMOVE THE -1.
                $startPos = $fileSize - $endPos - 1;
                $endPos = $fileSize - 1;
            } elseif ($endPos === '' && $startPos !== '') {
                $startPos = (int) $startPos;
                // The -1 is necessary and not a hack because byte ranges are inclusive and start
                // at 0. DO NOT REMOVE THE -1.
                $endPos = $fileSize - 1;
            } else {
                $startPos = (int) $startPos;
                $endPos = (int) $endPos;
            }
            
            if ($startPos >= $fileSize || $endPos <= $startPos || $endPos <= 0) {
                return NULL;
            }
            
            $normalizedByteRanges[] = [$startPos, $endPos];
        }
        
        return $normalizedByteRanges;
    }
    
    private function getEtag($mTime, $fileSize, $filePath) {
        $hashable = $mTime;
        
        if ($this->eTagMode & self::ETAG_SIZE) {
            $hashable .= $fileSize;
        }
        
        if ($this->eTagMode & self::ETAG_INODE) {
            $hashable .= fileinode($filePath);
        }
        
        return md5($hashable);
    }
    
    private function getMimeType($filePath) {
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        
        if ($ext === '') {
            return NULL;
        }
        
        $ext = strtolower($ext);
        
        if (isset($this->customMimeTypes[$ext])) {
            return $this->customMimeTypes[$ext];
        } elseif (isset($this->mimeTypes[$ext])) {
            return $this->mimeTypes[$ext];
        } else {
            return NULL;
        }
    }
    
    private function options() {
        $status = Status::OK;
        $reason = Reason::HTTP_200;
        $headers = [
            'Allow: GET, HEAD, OPTIONS',
            'Accept-Ranges: bytes'
        ];
        
        return [$status, $reason, $headers, NULL];
    }
    
    private function methodNotAllowed() {
        $status = Status::METHOD_NOT_ALLOWED;
        $reason = Reason::HTTP_405;
        $headers = [
            'Allow: GET, HEAD, OPTIONS'
        ];
        
        return [$status, $reason, $headers, NULL];
    }
    
    private function requestedRangeNotSatisfiable($fileSize) {
        $status = Status::REQUESTED_RANGE_NOT_SATISFIABLE;
        $reason = Reason::HTTP_416;
        $headers = [
            "Content-Range: */{$fileSize}"
        ];
        
        return [$status, $reason, $headers, NULL];
    }
    
    private function notModified($eTag, $lastModified) {
        $status = Status::NOT_MODIFIED;
        $reason = Reason::HTTP_304;
        $headers = [
            'Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' UTC'
        ];
        
        if ($eTag) {
            $headers[] = "ETag: {$eTag}";
        }
        
        return [$status, $reason, $headers, NULL];
    }
    
    private function preconditionFailed() {
        $status = Status::PRECONDITION_FAILED;
        $reason = Reason::HTTP_412;
        
        return [$status, $reason, $headers = [], NULL];
    }
    
    private function notFound() {
        $status = Status::NOT_FOUND;
        $reason = Reason::HTTP_404;
        $body = '<html><body><h1>404 Not Found</h1></body></html>';
        $headers = [
            'Content-Type: text/html',
            'Content-Length: ' . strlen($body),
        ];
        
        return [$status, $reason, $headers, $body];
    }
    
    function assignDefaultMimeTypes() {
        $this->mimeTypes = [
            "323"       => "text/h323",
            "acx"       => "application/internet-property-stream",
            "ai"        => "application/postscript",
            "aif"       => "audio/x-aiff",
            "aifc"      => "audio/x-aiff",
            "aiff"      => "audio/x-aiff",
            "asf"       => "video/x-ms-asf",
            "asr"       => "video/x-ms-asf",
            "asx"       => "video/x-ms-asf",
            "au"        => "audio/basic",
            "avi"       => "video/x-msvideo",
            "axs"       => "application/olescript",
            "bas"       => "text/plain",
            "bcpio"     => "application/x-bcpio",
            "bin"       => "application/octet-stream",
            "bmp"       => "image/bmp",
            "c"         => "text/plain",
            "cat"       => "application/vnd.ms-pkiseccat",
            "cdf"       => "application/x-cdf",
            "cdf"       => "application/x-netcdf",
            "cer"       => "application/x-x509-ca-cert",
            "class"     => "application/octet-stream",
            "clp"       => "application/x-msclip",
            "cmx"       => "image/x-cmx",
            "cod"       => "image/cis-cod",
            "cpio"      => "application/x-cpio",
            "crd"       => "application/x-mscardfile",
            "crl"       => "application/pkix-crl",
            "crt"       => "application/x-x509-ca-cert",
            "csh"       => "application/x-csh",
            "css"       => "text/css",
            "dcr"       => "application/x-director",
            "der"       => "application/x-x509-ca-cert",
            "dir"       => "application/x-director",
            "dll"       => "application/x-msdownload",
            "dms"       => "application/octet-stream",
            "doc"       => "application/msword",
            "dot"       => "application/msword",
            "dvi"       => "application/x-dvi",
            "dxr"       => "application/x-director",
            "eps"       => "application/postscript",
            "etx"       => "text/x-setext",
            "evy"       => "application/envoy",
            "exe"       => "application/octet-stream",
            "fif"       => "application/fractals",
            "flr"       => "x-world/x-vrml",
            "gif"       => "image/gif",
            "gtar"      => "application/x-gtar",
            "gz"        => "application/x-gzip",
            "h"         => "text/plain",
            "hdf"       => "application/x-hdf",
            "hlp"       => "application/winhlp",
            "hqx"       => "application/mac-binhex40",
            "hta"       => "application/hta",
            "htc"       => "text/x-component",
            "htm"       => "text/html",
            "html"      => "text/html",
            "htt"       => "text/webviewhtml",
            "ico"       => "image/x-icon",
            "ief"       => "image/ief",
            "iii"       => "application/x-iphone",
            "ins"       => "application/x-internet-signup",
            "isp"       => "application/x-internet-signup",
            "jfif"      => "image/pipeg",
            "jpe"       => "image/jpeg",
            "jpeg"      => "image/jpeg",
            "jpg"       => "image/jpeg",
            "js"        => "application/x-javascript",
            "latex"     => "application/x-latex",
            "lha"       => "application/octet-stream",
            "lsf"       => "video/x-la-asf",
            "lsx"       => "video/x-la-asf",
            "lzh"       => "application/octet-stream",
            "m13"       => "application/x-msmediaview",
            "m14"       => "application/x-msmediaview",
            "m3u"       => "audio/x-mpegurl",
            "man"       => "application/x-troff-man",
            "mdb"       => "application/x-msaccess",
            "me"        => "application/x-troff-me",
            "mht"       => "message/rfc822",
            "mhtml"     => "message/rfc822",
            "mid"       => "audio/mid",
            "mny"       => "application/x-msmoney",
            "mov"       => "video/quicktime",
            "movie"     => "video/x-sgi-movie",
            "m4a"       => "audio/mp4",
            "mp2"       => "video/mpeg",
            "mp3"       => "audio/mpeg",
            "mpa"       => "video/mpeg",
            "mpe"       => "video/mpeg",
            "mpeg"      => "video/mpeg",
            "mpg"       => "video/mpeg",
            "mpp"       => "application/vnd.ms-project",
            "mpv2"      => "video/mpeg",
            "ms"        => "application/x-troff-ms",
            "msg"       => "application/vnd.ms-outlook",
            "mvb"       => "application/x-msmediaview",
            "nc"        => "application/x-netcdf",
            "nws"       => "message/rfc822",
            "oda"       => "application/oda",
            "ogg"       => "audio/ogg",
            "oga"       => "audio/ogg",
            "p10"       => "application/pkcs10",
            "p12"       => "application/x-pkcs12",
            "p7b"       => "application/x-pkcs7-certificates",
            "p7c"       => "application/x-pkcs7-mime",
            "p7m"       => "application/x-pkcs7-mime",
            "p7r"       => "application/x-pkcs7-certreqresp",
            "p7s"       => "application/x-pkcs7-signature",
            "pbm"       => "image/x-portable-bitmap",
            "pdf"       => "application/pdf",
            "pfx"       => "application/x-pkcs12",
            "pgm"       => "image/x-portable-graymap",
            "pko"       => "application/ynd.ms-pkipko",
            "pma"       => "application/x-perfmon",
            "pmc"       => "application/x-perfmon",
            "pml"       => "application/x-perfmon",
            "pmr"       => "application/x-perfmon",
            "pmw"       => "application/x-perfmon",
            "png"       => "image/png",
            "pnm"       => "image/x-portable-anymap",
            "pot"       => "application/vnd.ms-powerpoint",
            "ppm"       => "image/x-portable-pixmap",
            "pps"       => "application/vnd.ms-powerpoint",
            "ppt"       => "application/vnd.ms-powerpoint",
            "prf"       => "application/pics-rules",
            "ps"        => "application/postscript",
            "pub"       => "application/x-mspublisher",
            "qt"        => "video/quicktime",
            "ra"        => "audio/x-pn-realaudio",
            "ram"       => "audio/x-pn-realaudio",
            "ras"       => "image/x-cmu-raster",
            "rgb"       => "image/x-rgb",
            "rmi"       => "audio/mid",
            "roff"      => "application/x-troff",
            "rtf"       => "application/rtf",
            "rtx"       => "text/richtext",
            "scd"       => "application/x-msschedule",
            "sct"       => "text/scriptlet",
            "sh"        => "application/x-sh",
            "shar"      => "application/x-shar",
            "sit"       => "application/x-stuffit",
            "snd"       => "audio/basic",
            "spc"       => "application/x-pkcs7-certificates",
            "spl"       => "application/futuresplash",
            "src"       => "application/x-wais-source",
            "sst"       => "application/vnd.ms-pkicertstore",
            "stl"       => "application/vnd.ms-pkistl",
            "stm"       => "text/html",
            "svg"       => "image/svg+xml",
            "swf"       => "application/x-shockwave-flash",
            "t"         => "application/x-troff",
            "tar"       => "application/x-tar",
            "tcl"       => "application/x-tcl",
            "tex"       => "application/x-tex",
            "texi"      => "application/x-texinfo",
            "texinfo"   => "application/x-texinfo",
            "tgz"       => "application/x-compressed",
            "tif"       => "image/tiff",
            "tiff"      => "image/tiff",
            "tr"        => "application/x-troff",
            "trm"       => "application/x-msterminal",
            "tsv"       => "text/tab-separated-values",
            "txt"       => "text/plain",
            "uls"       => "text/iuls",
            "ustar"     => "application/x-ustar",
            "vcf"       => "text/x-vcard",
            "vrml"      => "x-world/x-vrml",
            "wav"       => "audio/x-wav",
            "wcm"       => "application/vnd.ms-works",
            "wdb"       => "application/vnd.ms-works",
            "webma"     => "audio/webm",
            "wks"       => "application/vnd.ms-works",
            "wmf"       => "application/x-msmetafile",
            "wps"       => "application/vnd.ms-works",
            "wri"       => "application/x-mswrite",
            "wrl"       => "x-world/x-vrml",
            "wrz"       => "x-world/x-vrml",
            "xaf"       => "x-world/x-vrml",
            "xbm"       => "image/x-xbitmap",
            "xla"       => "application/vnd.ms-excel",
            "xlc"       => "application/vnd.ms-excel",
            "xlm"       => "application/vnd.ms-excel",
            "xls"       => "application/vnd.ms-excel",
            "xlt"       => "application/vnd.ms-excel",
            "xlw"       => "application/vnd.ms-excel",
            "xof"       => "x-world/x-vrml",
            "xpm"       => "image/x-xpixmap",
            "xwd"       => "image/x-xwindowdump",
            "z"         => "application/x-compress",
            "zip"       => "application/zip"
        ];
    }
    
    function __destruct() {
        $this->reactor->cancel($this->cacheWatcher);
    }
}


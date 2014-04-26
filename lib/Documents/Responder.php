<?php

namespace Aerys\Documents;

use Aerys\Server,
    Aerys\Status,
    Aerys\Reason,
    Aerys\Response,
    Aerys\ServerObserver,
    Alert\Reactor,
    Amp\Dispatcher;

class Responder implements ServerObserver {
    const OP_INDEXES = 1;
    const OP_ETAG_FLAGS = 2;
    const OP_EXPIRES_PERIOD = 3;
    const OP_DEFAULT_MIME = 4;
    const OP_DEFAULT_CHARSET = 5;
    const OP_ENABLE_CACHE = 6;
    const OP_CACHE_TTL = 7;
    const OP_CACHE_MAX_ENTRIES = 8;
    const OP_CACHE_MAX_ENTRY_SIZE = 9;

    private $root;
    private $reactor;
    private $dispatcher;
    private $mimeTypes;
    private $notFoundResponse;
    private $precondFailedResponse;
    private $optionsResponse;
    private $methodNotAllowedResponse;

    private $indexes = ['index.html', 'index.htm'];
    private $etagFlags = Etag::ALL;
    private $expiresPeriod = 3600;
    private $defaultMimeType = 'text/plain';
    private $defaultCharset = 'utf-8';
    private $enableCache = TRUE;
    private $cacheTtl = 5;
    private $maxCacheEntries = 100;
    private $maxCacheEntrySize = 1048576;

    private $now;
    private $cache = [];
    private $cacheTimeouts = [];
    private $cacheCount = 0;
    private $cacheCollectWatcher;

    private $boundary;
    private $multipartTemplate = "--%s\r\nContent-Type: %s\r\nContent-Range: bytes %d-%d/%d\r\n\r\n";

    private static $PRECOND_NOT_MODIFIED = 1;
    private static $PRECOND_FAILED = 2;
    private static $PRECOND_IF_RANGE_OK = 3;
    private static $PRECOND_IF_RANGE_FAILED = 4;
    private static $PRECOND_PASS = 5;

    public function __construct($root, Reactor $reactor, Dispatcher $dispatcher) {
        $this->setRoot($root);
        $this->reactor = $reactor;
        $this->dispatcher = $dispatcher;

        $dispatcher->addStartTask(new StatStarterTask(
            $this->root,
            $this->etagFlags,
            $this->indexes,
            $this->maxCacheEntrySize
        ));

        $this->boundary = uniqid('', TRUE);
        $this->assignDefaultMimeTypes();
        $this->initializeReusableResponses();
    }

    private function setRoot($root) {
        $path = str_replace('\\', '/', $root);

        if (!(is_readable($path) && is_dir($path))) {
            throw new \InvalidArgumentException(
                'Document root must be a readable directory'
            );
        }

        $this->root = rtrim($path, '/');
    }

    private function initializeReusableResponses() {
        $this->notFoundResponse = (new Response)
            ->setStatus(404)
            ->setHeader('Content-Type', 'text/html; charset=utf-8')
            ->setBody('<html><body><h1>404 Not Found</h1></body></html>')
        ;

        $this->precondFailedResponse = (new Response)
            ->setStatus(Status::PRECONDITION_FAILED)
        ;

        $this->optionsResponse = (new Response)
            ->setStatus(Status::OK)
            ->setHeader('Allow', 'GET, HEAD, OPTIONS')
            ->setHeader('Accept-Ranges', 'bytes')
        ;

        $this->methodNotAllowedResponse = (new Response)
            ->setStatus(Status::METHOD_NOT_ALLOWED)
            ->setHeader('Allow', 'GET, HEAD, OPTIONS')
        ;
    }

    public function __invoke($request) {
        $path = $request['REQUEST_URI_PATH'];

        if (isset($this->cache[$path])) {
            $statStruct = $this->cache[$path];
            return ($statStruct === FALSE)
                ? $this->notFoundResponse
                : $this->respond($path, $request, $statStruct);
        }

        return $this->generateResponse($path, $request);
    }

    private function generateResponse($path, $request) {
        $statStruct = (yield $this->dispatcher->execute(new StatTask($path)));

        if ($this->enableCache) {
            $this->cacheStatStruct($path, $statStruct);
        }

        if ($statStruct === FALSE) {
            yield $this->notFoundResponse;
        } else {
            yield $this->respond($path, $request, $statStruct);
        }
    }

    private function cacheStatStruct($path, $statStruct) {
        // It's important to use unset() here because we need to
        // place the expiration timeout at the back of the queue.
        unset($this->cacheTimeouts[$path]);
        $this->cacheTimeouts[$path] = $this->now + $this->cacheTtl;
        $this->cache[$path] = $statStruct;

        if (++$this->cacheCount > $this->maxCacheEntries) {
            reset($this->cache);
            $key = key($this->cache);
            unset($this->cacheTimeouts[$key]);
        }

        if ($this->cacheCount === 1) {
            $this->reactor->enable($this->cacheCollectWatcher);
        }
    }

    private function respond($path, $request, $statStruct) {
        $method = $request['REQUEST_METHOD'];

        if ($method === 'OPTIONS') {
            return $this->optionsResponse;
        }

        if (!($method === 'GET' || $method === 'HEAD')) {
            return $this->methodNotAllowedResponse;
        }

        $ranges = empty($request['HTTP_RANGE']) ? NULL : $request['HTTP_RANGE'];
        $sendRangeResponse = (bool) $ranges;

        list($path, $size, $mtime, $etag) = $statStruct;

        switch ($this->checkPreconditions($request, $mtime, $size, $etag)) {
            case self::$PRECOND_NOT_MODIFIED:
                return $this->makeNotModifiedResponse($etag, $mtime);
            case self::$PRECOND_FAILED:
                return $this->precondFailedResponse;
            case self::$PRECOND_IF_RANGE_OK:
                $sendRangeResponse = TRUE;
                break;
            case self::$PRECOND_IF_RANGE_FAILED:
                $sendRangeResponse = FALSE;
                break;
        }

        $proto = $request['SERVER_PROTOCOL'];

        /*
        // @TODO Restore this once range responses are fixed
        return $sendRangeResponse
            ? $this->makeRangeResponseWriter($statStruct, $proto, $ranges)
            : $this->makeResponseWriter($statStruct, $proto);
        */

        return $this->makeResponseWriter($statStruct, $proto);
    }

    private function checkPreconditions($request, $mtime, $size, $etag) {
        $ifMatchHeader = !empty($request['HTTP_IF_MATCH'])
            ? $request['HTTP_IF_MATCH']
            : NULL;

        if ($ifMatchHeader && !$this->etagMatchesPrecondition($etag, $ifMatchHeader)) {
            return self::$PRECOND_FAILED;
        }

        $ifNoneMatchHeader = !empty($request['HTTP_IF_NONE_MATCH'])
            ? $request['HTTP_IF_NONE_MATCH']
            : NULL;

        if ($ifNoneMatchHeader && $this->etagMatchesPrecondition($etag, $ifNoneMatchHeader)) {
            return self::$PRECOND_NOT_MODIFIED;
        }

        $ifModifiedSinceHeader = !empty($request['HTTP_IF_MODIFIED_SINCE'])
            ? @strtotime($request['HTTP_IF_MODIFIED_SINCE'])
            : NULL;

        if ($ifModifiedSinceHeader && $ifModifiedSinceHeader <= $mtime) {
            return self::$PRECOND_NOT_MODIFIED;
        }

        $ifUnmodifiedSinceHeader = !empty($request['HTTP_IF_UNMODIFIED_SINCE'])
            ? @strtotime($request['HTTP_IF_UNMODIFIED_SINCE'])
            : NULL;

        if ($ifUnmodifiedSinceHeader && $mtime > $ifUnmodifiedSinceHeader) {
            return self::$PRECOND_FAILED;
        }

        $ifRangeHeader = !empty($request['HTTP_IF_RANGE'])
            ? $request['HTTP_IF_RANGE']
            : NULL;

        if ($ifRangeHeader) {
            return $this->ifRangeMatchesPrecondition($etag, $mtime, $ifRangeHeader)
                ? self::$PRECOND_IF_RANGE_OK
                : self::$PRECOND_IF_RANGE_FAILED;
        }

        return self::$PRECOND_PASS;
    }

    private function etagMatchesPrecondition($etag, $headerStringOrArray) {
        $etagArr = is_string($headerStringOrArray)
            ? explode(',', $headerStringOrArray)
            : $headerStringOrArray;

        foreach ($etagArr as $value) {
            if ($etag == $value) {
                return TRUE;
            }
        }

        return FALSE;
    }

    private function ifRangeMatchesPrecondition($etag, $mtime, $ifRangeHeader) {
        return ($httpDate = @strtotime($ifRangeHeader))
            ? ($mtime <= $httpDate)
            : ($etag === $ifRangeHeader);
    }

    private function makeResponseWriter($statStruct, $proto) {
        list($path, $size, $mtime, $etag, $buffer) = $statStruct;

        $headers = $this->buildCommonHeaders($mtime, $etag);
        $headers[] = "Content-Length: {$size}";
        $headers[] = $this->generateContentTypeHeader($path);
        $headers = implode("\r\n", $headers);

        $startLineAndHeaders = "HTTP/{$proto} 200 OK\r\n{$headers}";

        // If we already have the entity body buffered we can write the response as a single string.
        // Otherwise we use a thread to do our filesystem IO without blocking the event loop.
        return isset($buffer)
            ? new StringResponseWriter($this->reactor, $startLineAndHeaders, $buffer)
            : new ThreadResponseWriter($this->dispatcher, $startLineAndHeaders, $path, $size);
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

    private function makeRangeResponse($statStruc, $proto, $ranges) {
        list($path, $size, $mtime, $etag, $buffer) = $statStruct;

        $response = new Response;
        $ranges = $this->normalizeByteRanges($size, $ranges);

        if (empty($ranges)) {
            $response->setStatus(Status::REQUESTED_RANGE_NOT_SATISFIABLE);
            $response->setHeader('Content-Range' , "*/{$size}");

            return $response;
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

        $response->setStatus(Status::PARTIAL_CONTENT);
        $response->applyRawHeaderLines($headers);
        $response->setBody($body);

        return $response;
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
        $headers[] = "Content-Type:  multipart/byteranges; boundary={$this->boundary}";

        return $headers;
    }

    private function calculateMultipartLength($mime, $ranges, $size) {
        $totalSize = 0;
        $templateSize = strlen($this->multipartTemplate) - 10; // Don't count sprintf format strings
        $boundarySize = strlen($this->boundary);
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

            if ($startPos >= $size || $endPos <= $startPos || $endPos <= 0) {
                return NULL;
            }

            $normalizedByteRanges[] = [$startPos, $endPos];
        }

        return $normalizedByteRanges;
    }

    private function getMimeTypeFromPath($path) {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        if ($ext == '') {
            return NULL;
        }
        $ext = strtolower($ext);

        return isset($this->mimeTypes[$ext]) ? $this->mimeTypes[$ext] : NULL;
    }

    private function makeNotModifiedResponse($etag, $mtime) {
        $response = new Response;
        $response->setStatus(Status::NOT_MODIFIED);
        $response->addHeader('Last-Modified',  gmdate('D, d M Y H:i:s', $mtime) . ' UTC');

        if ($etag) {
            $response->addHeader('ETag', $etag);
        }

        return $response;
    }

    public function assignDefaultMimeTypes() {
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

    /**
     * Retrieve the current value for the specified option
     *
     * @param string $option
     * @throws \DomainException On unknown option
     * @return mixed Returns the specified option's value
     */
    public function getOption($option) {
        switch ($option) {
            case self::OP_INDEXES:
                return $this->indexes;
            case self::OP_ETAG_FLAGS:
                return $this->etagFlags;
            case self::OP_EXPIRES_PERIOD:
                return $this->expiresPeriod;
            case self::OP_DEFAULT_MIME:
                return $this->defaultMimeType;
            case self::OP_DEFAULT_CHARSET:
                return $this->defaultCharset;
            case self::OP_ENABLE_CACHE:
                return $this->enableCache;
            case self::OP_CACHE_TTL:
                return $this->cacheTtl;
            case self::OP_CACHE_MAX_ENTRIES:
                return $this->maxCacheEntries;
            case self::OP_CACHE_MAX_ENTRY_SIZE:
                return $this->maxCacheEntrySize;
            default:
                throw new \DomainException(
                    "Unknown option: {$option}"
                );
        }
    }

    /**
     * Set multiple DocRoot options
     *
     * @param array $options Key-value array mapping option name keys to values
     * @return \Aerys\Responders\Documents\DocRoot Returns the current object instance
     */
    public function setAllOptions(array $options) {
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
     * @return \Aerys\Responders\Documents\DocRoot Returns the current object instance
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
        $this->enableCache = filter_var($boolFlag, FILTER_VALIDATE_BOOLEAN);
    }

    private function setIndexes(array $indexes) {
        $this->indexes = $indexes;
    }

    private function setEtagFlags($mode) {
        $this->etagFlags = (int) $mode;
    }

    private function setExpiresPeriod($seconds) {
        $this->expiresPeriod = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => -1,
            'default' => 3600
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
        $this->defaultCharset = $charset;
    }

    private function setCacheTtl($seconds) {
        $this->maxCacheAge = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'default' => 30
        ]]);
    }

    private function setMaxCacheEntries($bytes) {
        $this->maxCacheEntries = filter_var($bytes, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'default' => 50
        ]]);
    }

    private function setMaxCacheEntrySize($bytes) {
        $this->maxCacheEntrySize = filter_var($bytes, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'default' => 1048576
        ]]);
    }

    public function __destruct() {
        $this->reactor->cancel($this->cacheCollectWatcher);
    }

    /**
     * Receive notifications from the server when it starts/stops
     *
     * @param \Aerys\Server $server
     * @param int $event
     */
    public function onServerUpdate(Server $server, $event) {
        switch ($event) {
            case Server::STARTING:
                $this->start();
                break;
        }
    }

    public function start() {
        $this->now = time();
        $this->dispatcher->start();

        if ($this->enableCache) {
            $this->cacheCollectWatcher = $this->reactor->repeat(function() {
                $this->collectStaleCacheEntries();
            }, $msDelay = 1000);
        }
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
            $this->reactor->disable($this->cacheCollectWatcher);
        }
    }
}

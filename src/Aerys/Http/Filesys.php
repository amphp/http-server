<?php

namespace Aerys\Http;

/**
 * @TODO Decide how best to periodically clear the file stat cache
 */
class Filesys implements Handler {
    
    const ETAG_NONE = 0;
    const ETAG_SIZE = 1;
    const ETAG_INODE = 2;
    const ETAG_ALL = 3;
    
    const PRECONDITION_NOT_MODIFIED = 100;
    const PRECONDITION_FAILED = 200;
    const PRECONDITION_IF_RANGE_OK = 300;
    const PRECONDITION_IF_RANGE_FAILED = 400;
    const PRECONDITION_PASS = 999;
    
    private $docRoot;
    private $indexes = ['index.html', 'index.htm'];
    private $eTagMode = self::ETAG_ALL;
    private $staleAfter = 300;
    private $mimeTypes = [];
    private $customTypes = [];
    private $charset = 'utf-8';
    
    private $fdCache = [];
    private $fdCacheTimeout = 20;
    
    function __construct($docRoot = NULL) {
        if ($docRoot) {
            $this->setDocRoot($docRoot);
        }
        
        $this->setDefaultMimeTypes();
    }
    
    function setDocRoot($docRoot) {
        if (is_readable($docRoot) && is_dir($docRoot)) {
            $this->docRoot = rtrim($docRoot, '/');
        } else {
            throw new \RuntimeException(
                'Specified file system document root must be a readable directory'
            );
        }
    }
    
    function setIndexes(array $indexes) {
        $this->indexes = $indexes;
    }
    
    function setEtagMode($mode) {
        $this->eTagMode = (int) $mode;
    }
    
    function setStaleAfter($seconds) {
        $this->staleAfter = (int) $seconds;
    }
    
    function setTypes(array $mimeTypes) {
        foreach ($mimeTypes as $ext => $type) {
            $ext = strtolower(ltrim($ext, '.'));
            $this->customTypes[$ext] = $type;
        }
    }
    
    function setCharset($charset) {
        $this->charset = $charset;
    }
    
    function __invoke(array $asgiEnv, $requestId) {
        $requestUri = ($asgiEnv['PATH_INFO'] . $asgiEnv['SCRIPT_NAME']) ?: '/';
        $filePath = $this->docRoot . $requestUri;
        $fileExists = file_exists($filePath);
        
        // The `strpos` check is important to prevent access to files outside the docRoot when 
        // resolving a URI that contains leading "../" segments. Also, the boolean check for a truthy
        // $this->docRoot value allows docRoot values at the filesystem root directory "/"; it is
        // not an accident and should not be removed.
        if ($fileExists && $this->docRoot && (0 !== strpos(realpath($filePath), $this->docRoot))) {
            return $this->notFound();
        } elseif ($fileExists && !is_dir($filePath)) {
            return $this->respondToFoundFile($filePath, $asgiEnv);
        } elseif ($fileExists && $this->indexes && ($filePath = $this->matchIndex($filePath))) {
            return $this->respondToFoundFile($filePath, $asgiEnv);
        } else {
            return $this->notFound();
        }
    }
    
    private function matchIndex($dirPath) {
        foreach ($this->indexes as $indexFile) {
            $indexPath = $dirPath . $indexFile;
            if (file_exists($indexPath)) {
                return $indexPath;
            }
        }
        
        return NULL;
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
            case self::PRECONDITION_PASS:
                break;
            default:
                // This should never happen, but just in case
                throw new \DomainException;
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
        if (is_string($headerStringOrArray)) {
            return ($eTag == $headerStringOrArray);
        }
        
        foreach ($headerStringOrArray as $value) {
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
    
    private function doFile($filePath, $method, $mTime, $fileSize, $eTag) {
        $status = 200;
        $reason = 'OK';
        $now = time();
        
        $headers = [
            'Date' => date(HttpServer::HTTP_DATE, $now),
            'Expires' => date(HttpServer::HTTP_DATE, $now + $this->staleAfter),
            'Cache-Control' => 'public',
            'Content-Length' => $fileSize,
            'Last-Modified' => date(HttpServer::HTTP_DATE, $mTime),
            'Accept-Ranges' => 'bytes'
        ];
        
        $headers['Content-Type'] = $this->generateContentTypeHeader($filePath);
        
        if ($eTag) {
            $headers['ETag'] = $eTag;
        }
        
        $body = ($method == 'GET') ? $this->getFileDescriptor($filePath) : NULL;
        
        return [$status, $reason, $headers, $body];
    }
    
    private function getFileDescriptor($filePath) {
        $cacheId = strtolower($filePath);
        
        if (!isset($this->fdCache[$cacheId])) {
            $fd = fopen($filePath, 'rb');
            $cacheExpiry = time() + $this->fdCacheTimeout;
            $this->fdCache[$cacheId] = [$fd, $cacheExpiry];
            
            return $fd;
        }
        
        list($fd, $cacheExpiry) = $this->fdCache[$cacheId];
        
        if ($cacheExpiry < time()) {
            unset($this->fdCache[$cacheId]);
        }
        
        return $fd;
    }
    
    private function generateContentTypeHeader($filePath) {
        $contentType = $this->getMimeType($filePath) ?: 'application/octet-stream';
        
        if (0 === stripos($contentType, 'text/')) {
            $contentType .= '; charset=' . $this->charset;
        }
        
        return $contentType;
    }
    
    private function doRange($filePath, $method, $ranges, $mTime, $fileSize, $eTag) {
        if (is_array($ranges)
            || (0 !== stripos($ranges, 'bytes='))
            || !strstr($ranges, '-')
            || !($ranges = $this->normalizeByteRanges($fileSize, $ranges))
        ) {
           return $this->requestedRangeNotSatisfiable($fileSize);
        }
        
        $now = time();
        $body = $this->getFileDescriptor($filePath);
        $status = 206;
        $reason = 'Partial Content';
        $headers = [
            'Date' => date(HttpServer::HTTP_DATE, $now),
            'Expires' => date(HttpServer::HTTP_DATE, $now + $this->staleAfter),
            'Cache-Control' => 'public',
            'Last-Modified' => date(HttpServer::HTTP_DATE, $mTime)
        ];
        
        if ($eTag) {
            $headers['ETag'] = $eTag;
        }
        
        if ($isMultiPart = (count($ranges) > 1)) {
            $boundary = uniqid();
            $headers['Content-Type'] = 'multipart/byteranges; boundary=' . $boundary;
            
            $body = ($method == 'GET')
                ? new MultiPartByteRangeBody($body, $ranges, $boundary, $contentType, $fileSize)
                : NULL;
            
        } else {
            list($startPos, $endPos) = $ranges[0];
            $headers['Content-Length'] = $endPos - $startPos;
            $headers['Content-Range'] = "bytes $startPos-$endPos/$fileSize";
            $headers['Content-Type'] = $this->generateContentTypeHeader($filePath);
            $body = ($method == 'GET')
                ? new ByteRangeBody($body, $startPos, $endPos)
                : NULL;
        }
        
        return [$status, $reason, $headers, $body];
    }
    
    private function normalizeByteRanges($fileSize, $ranges) {
        $normalized = [];
        
        $ranges = substr($ranges, 6);
        $ranges = explode(',', $ranges);
        
        foreach ($ranges as $range) {
            list($startPos, $endPos) = explode('-', rtrim($range));
            
            if ($startPos === '' && $endPos === '') {
                return NULL;
            } elseif ($startPos === '' && $endPos !== '') {
                // The -1 is necessary and not a hack because byte ranges start at 0. Don't remove it.
                $startPos = $fileSize - $endPos - 1;
                $endPos = $fileSize - 1;
            } elseif ($endPos === '' && $startPos !== '') {
                $startPos = (int) $startPos;
                // The -1 is necessary and not a hack because byte ranges start at 0. Don't remove it.
                $endPos = $fileSize - 1;
            } else {
                $startPos = (int) $startPos;
                $endPos = (int) $endPos;
            }
            
            if ($startPos >= $fileSize || $endPos <= $startPos || $endPos <= 0) {
                return NULL;
            }
            
            $normalized[] = [$startPos, $endPos];
        }
        
        return $normalized;
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
        
        if (isset($this->customTypes[$ext])) {
            return $this->customTypes[$ext];
        } elseif (isset($this->mimeTypes[$ext])) {
            return $this->mimeTypes[$ext];
        } else {
            return NULL;
        }
    }
    
    private function options() {
        $status = 200;
        $reason = 'OK';
        $headers = [
            'Allow' => 'GET, HEAD, OPTIONS',
            'Accept-Ranges' => 'bytes'
        ];
        
        return [$status, $reason, $headers, NULL];
    }
    
    private function methodNotAllowed() {
        $status = 405;
        $reason = 'Method Not Allowed';
        $headers = [
            'Date' => date(HttpServer::HTTP_DATE),
            'Allow' => 'GET, HEAD, OPTIONS'
        ];
        
        return [$status, $reason, $headers, NULL];
    }
    
    private function requestedRangeNotSatisfiable($fileSize) {
        $status = 416;
        $reason = 'Requested Range Not Satisfiable';
        $headers = [
            'Date' => date(HttpServer::HTTP_DATE, time()),
            'Content-Range' => '*/' . $fileSize
        ];
        
        return [$status, $reason, $headers, NULL];
    }
    
    private function notModified($eTag, $lastModified) {
        $status = 304;
        $reason = 'Not Modified';
        $headers = [
            'Date' => date(HttpServer::HTTP_DATE),
            'Last-Modified' => date(HttpServer::HTTP_DATE, $lastModified)
        ];
        
        if ($eTag) {
            $headers['ETag'] = $eTag;
        }
        
        return [$status, $reason, $headers, NULL];
    }
    
    private function preconditionFailed() {
        $status = 412;
        $reason = 'Precondition Failed';
        $headers = [
            'Date' => date(HttpServer::HTTP_DATE)
        ];
        
        return [$status, $reason, $headers, NULL];
    }
    
    private function notFound() {
        $status = 404;
        $reason = 'Not Found';
        $body = '<html><body><h1>404 Not Found</h1></body></html>';
        $headers = [
            'Date' => date(HttpServer::HTTP_DATE),
            'Content-Type' => 'text/html',
            'Content-Length' => strlen($body),
        ];
        
        return [$status, $reason, $headers, $body];
    }
    
    private function setDefaultMimeTypes() {
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
}


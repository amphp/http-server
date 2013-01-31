<?php

namespace Aerys\Handlers;

use Aerys\Server;

class Filesys {
    
    const ETAG_NONE = 0;
    const ETAG_SIZE = 1;
    const ETAG_INODE = 2;
    const ETAG_ALL = 3;
    
    private $docRoot;
    private $indexes = ['index.html', 'index.htm'];
    private $staleAfter = 60;
    private $customTypes = [];
    private $eTagMode = self::ETAG_ALL;
    
    private static $mimeTypes = [
        "323"       => "text/h323",
        "*"         => "application/octet-stream",
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
        "setpay"    => "application/set-payment-initiation",
        "setreg"    => "application/set-registration-initiation",
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
        "sv4cpio"   => "application/x-sv4cpio",
        "sv4crc"    => "application/x-sv4crc",
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
    
    /**
     * @todo determine appropriate exception to throw on an unreadable $docRoot
     */
    function __construct($docRoot) {
        if (is_readable($docRoot) && is_dir($docRoot)) {
            $this->docRoot = rtrim($docRoot, '/');
        } else {
            throw new \Exception;
        }
    }
    
    function setIndexes(array $indexes) {
        $this->indexes = $indexes;
    }
    
    function setTypes(array $mimeTypes) {
        foreach ($mimeTypes as $ext => $type) {
            $ext = strtolower(ltrim($ext, '.'));
            $this->customTypes[$ext] = $type;
        }
    }
    
    function setEtagMode($mode) {
        $this->eTagMode = (int) $mode;
    }
    
    function setStaleAfter($seconds) {
        $this->staleAfter = (int) $seconds;
    }
    
    function __invoke(array $asgiEnv) {
        $requestUri = ($asgiEnv['PATH_INFO'] . $asgiEnv['SCRIPT_NAME']) ?: '/';
        $filePath = $this->docRoot . $requestUri;
        $fileExists = file_exists($filePath);
        
        // The `strpos` check is important to prevent access to files outside the docRoot when 
        // resolving a URI that contains leading "../" segments
        if ($fileExists && (0 !== strpos(realpath($filePath), $this->docRoot))) {
            return $this->notFound();
        } elseif ($fileExists && !is_dir($filePath)) {
            return $this->processExistingFile($filePath, $asgiEnv);
        } elseif ($fileExists && ($filePath = $this->matchIndex($filePath))) {
            return $this->processExistingFile($filePath, $asgiEnv);
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
    
    private function processExistingFile($filePath, array $asgiEnv) {
        $requestMethod = $asgiEnv['REQUEST_METHOD'];
        
        if (!($requestMethod == 'GET' || $requestMethod == 'HEAD')) {
            return $this->methodNotAllowed();
        }
        
        $mTime = filemtime($filePath);
        $fileSize = filesize($filePath);
        $eTag = !$this->eTagMode ? NULL : $this->getEtag($mTime, $fileSize);
        
        if (isset($asgiEnv['HTTP_IF_NONE_MATCH']) && $eTag == $asgiEnv['HTTP_IF_NONE_MATCH']) {
            return $this->notModified();
        } elseif (isset($asgiEnv['HTTP_IF-MODIFIED-SINCE'])
            && ($comparisonTime = @strtotime($asgiEnv['HTTP_IF-MODIFIED-SINCE']))
            && $comparisonTime < $mTime
        ) {
            return $this->notModified();
        } else {
            $status = 200;
            $now = time();
            $headers = [
                'Date' => date(Server::HTTP_DATE, $now),
                'Expires' => date(Server::HTTP_DATE, $now + $this->staleAfter),
                'Content-Length' => $fileSize,
                'Last-Modified' => date('D, d M Y H:i:s T', $mTime)
            ];
            
            if ($eTag) {
                $headers['ETag'] = $eTag;
            }
            
            if ($mimeType = $this->getMimeType($filePath)) {
                $headers['Content-Type'] = $mimeType;
            }
            
            $body = ($requestMethod == 'GET') ? fopen($filePath, 'r') : NULL;
            
            return [$status, $headers, $body];
        }
    }
    
    private function getEtag($mTime, $fileSize) {
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
        } elseif (isset(self::$mimeTypes[$ext])) {
            return self::$mimeTypes[$ext];
        } else {
            return NULL;
        }
    }
    
    private function methodNotAllowed() {
        $status = 405;
        $headers = [
            'Date' => date('D, d M Y H:i:s T'),
            'Allow' => 'GET, HEAD'
        ];
        
        return [$status, $headers, NULL];
    }
    
    private function notModified() {
        $status = 304;
        $headers = [
            'Date' => date('D, d M Y H:i:s T')
        ];
        
        return [304, $headers, NULL];
    }
    
    private function notFound() {
        $status = 404;
        $body = '<html><body><h1>404 Not Found</h1></body></html>';
        $headers = [
            'Date' => date('D, d M Y H:i:s T'),
            'Content-Type' => 'text/html',
            'Content-Length' => strlen($body),
        ];
        
        return [$status, $headers, $body];
    }
}


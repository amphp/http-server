<?php

namespace Aerys;

use Amp\Struct;

class Options {
    use Struct;

    const MAX_DEFLATE_ENABLE_CACHE_SIZE = 1024;

    private $debug;
    private $user = null;
    private $maxConnections = 1000;
    private $connectionsPerIP = 30; // IPv4: /32, IPv6: /56 (per RFC 6177)
    private $maxKeepAliveRequests = 1000;
    private $keepAliveTimeout = 6; // seconds
    private $defaultContentType = "text/html"; // can be vhost
    private $defaultTextCharset = "utf-8"; // can be vhost
    private $sendServerToken = false;
    private $disableKeepAlive = false;
    private $socketBacklogSize = 128;
    private $normalizeMethodCase = true;
    private $maxConcurrentStreams = 20;
    private $maxFramesPerSecond = 60;
    private $allowedMethods = ["GET", "POST", "PUT", "PATCH", "HEAD", "OPTIONS", "DELETE"];
    private $deflateEnable;
    private $deflateContentTypes = '#^(?:text/.*+|[^/]*+/xml|[^+]*\+xml|application/(?:json|(?:x-)?javascript))$#i';
    private $configPath = null;
    
    private $maxFieldLen = 16384; // this must be strictly less than maxBodySize
    private $maxInputVars = 200;
    private $maxBodySize = 131072;
    private $maxHeaderSize = 32768;
    private $ioGranularity = 32768; // recommended: at least 16 KB
    private $softStreamCap = 131072; // should be multiple of outputBufferSize

    //@link http://webmasters.stackexchange.com/questions/31750/what-is-recommended-minimum-object-size-for-deflate-performance-benefits
    private $deflateMinimumLength = 860; // can be vhost
    private $deflateBufferSize = 8192; // can be vhost
    private $chunkBufferSize = 8192; // can be vhost
    private $outputBufferSize = 8192; // can be vhost

    private $shutdownTimeout = 3000; // milliseconds

    private $_dynamicCache;

    private $__initialized = false;

    public function __construct() {
        $this->deflateEnable = \extension_loaded("zlib");

        $this->_dynamicCache = new class {
            public $deflateContentTypes = [];
        };
    }

    /**
     * Allow retrieval of "public" properties
     *
     * @param string $property
     * @return mixed Returns the value of the requested property
     * @throws \DomainException If an unknown property is requested
     */
    public function __get(string $property) {
        if (\property_exists($this, $property)) {
            return $this->{$property};
        } else {
            // Use \Amp\Struct::generateStructPropertyError() to get a nice message
            // with a possible suggestion for the correct name
            throw new \DomainException(
                $this->generateStructPropertyError($property)
            );
        }
    }

    /**
     * Prevent external code from modifying our "public" time/date properties
     *
     * @param string $property
     * @param mixed $value
     * @throws \DomainException If an unknown property is requested
     */
    public function __set(string $property, $value) {
        if (!\property_exists($this, $property)) {
            // Use \Amp\Struct::generateStructPropertyError() to get a nice message
            // with a possible suggestion for the correct name
            throw new \DomainException(
                $this->generateStructPropertyError($property)
            );
        }

        if ($this->__initialized) {
            throw new \RuntimeException("Cannot alter options after initialization");
        }

        $setter = "set" . \ucfirst($property);
        if (\method_exists($this, $setter)) {
            return $this->{$setter}($value);
        } else {
            $this->{$property} = $value;
        }
    }

    private function setDebug(bool $flag) {
        if ($this->debug !== null) {
            throw new \LogicException(
                "Debug mode can't be altered at run-time"
            );
        }

        $this->debug = $flag;
    }

    // Note this is a required string|null parameter, just = null because we don't have proper union types with null yet...
    private function setUser(string $user = null) {
        $this->user = $user;
    }

    private function setMaxConnections(int $count) {
        if ($count < 1) {
            throw new \DomainException(
                "Max connections setting must be greater than or equal to one"
            );
        }

        $this->maxConnections = $count;
    }

    private function setConnectionsPerIP(int $count) {
        if ($count < 1) {
            throw new \DomainException(
                "Connections per IP maximum must be greater than or equal to one"
            );
        }

        $this->connectionsPerIP = $count;
    }

    private function setMaxKeepAliveRequests(int $count) {
        $this->maxKeepAliveRequests = $count;
    }

    private function setKeepAliveTimeout(int $seconds) {
        if ($seconds < 1) {
            throw new \DomainException(
                "Keep alive timeout setting must be greater than or equal to one second"
            );
        }

        $this->keepAliveTimeout = $seconds;
    }

    private function setDefaultContentType(string $contentType) {
        $this->defaultContentType = $contentType;
    }

    private function setDefaultTextCharset(string $charset) {
        $this->defaultTextCharset = $charset;
    }

    private function setSendServerToken(bool $flag) {
        $this->sendServerToken = $flag;
    }

    private function setDisableKeepAlive(bool $flag) {
        $this->disableKeepAlive = $flag;
    }

    private function setSocketBacklogSize(int $backlog) {
        if ($backlog < 16) {
            throw new \DomainException(
                "Socket backlog size setting must be greater than or equal to 16"
            );
        }

        $this->socketBacklogSize = $backlog;
    }

    private function setNormalizeMethodCase(bool $flag) {
        $this->normalizeMethodCase = $flag;
    }

    private function setMaxInputVars(int $bytes) {
        if ($bytes < 0) {
            throw new \DomainException(
                "Max input vars must be a positive integer"
            );
        }

        $this->maxInputVars = $bytes;
    }

    private function setMaxFieldLen(int $bytes) {
        if ($bytes <= 0) {
            throw new \DomainException(
                "Max field length must be greater than zero bytes"
            );
        }

        $this->maxFieldLen = $bytes;
    }


    private function setMaxBodySize(int $bytes) {
        if ($bytes < 0) {
            throw new \DomainException(
                "Max body size setting must be greater than or equal to zero"
            );
        }

        $this->maxBodySize = $bytes;
    }

    private function setMaxHeaderSize(int $bytes) {
        if ($bytes <= 0) {
            throw new \DomainException(
                "Max header size setting must be greater than zero"
            );
        }

        $this->maxHeaderSize = $bytes;
    }

    private function setSoftStreamCap(int $bytes) {
        if ($bytes < 0) {
            throw new \DomainException(
                "Soft stream cap setting must be greater than or equal to zero"
            );
        }

        $this->softStreamCap = $bytes;
    }

    private function setMaxConcurrentStreams(int $streams) {
        if ($streams <= 0) {
            throw new \DomainException(
                "Max number of concurrent streams setting must be greater than zero"
            );
        }

        $this->maxConcurrentStreams = $streams;
    }

    private function setMaxFramesPerSecond(int $frames) {
        if ($frames <= 0) {
            throw new \DomainException(
                "Max number of HTTP/2 frames per second setting must be greater than zero"
            );
        }

        $this->maxFramesPerSecond = $frames;
    }

    private function setIoGranularity(int $bytes) {
        if ($bytes <= 0) {
            throw new \DomainException(
                "IO granularity setting must be greater than zero"
            );
        }

        $this->ioGranularity = $bytes;
    }

    private function setAllowedMethods(array $allowedMethods) {
        foreach ($allowedMethods as $key => $method) {
            if (!\is_string($method)) {
                throw new \DomainException(
                    \sprintf(
                        "Invalid type at key %s of allowed methods array: %s",
                        $key,
                        \is_object($method) ? \get_class($method) : \gettype($method)
                    )
                );
            }
            if ($method === "") {
                throw new \DomainException(
                    "Invalid empty HTTP method at key {$key} of allowed methods array"
                );
            }
        }
        if (!\in_array("GET", $allowedMethods)) {
            throw new \DomainException(
                "Servers must support GET as an allowed HTTP method"
            );
        }
        if (!\in_array("HEAD", $allowedMethods)) {
            throw new \DomainException(
                "Servers must support HEAD as an allowed HTTP method"
            );
        }

        $this->allowedMethods = $allowedMethods;
    }

    private function setDeflateEnable(bool $flag) {
        if ($flag && !\extension_loaded("zlib")) {
            throw new \DomainException(
                "Cannot enable deflate negotiation: zlib module required"
            );
        }

        $this->deflateEnable = $flag;
    }

    private function setDeflateContentTypes(string $regex) {
        if (!$regex || preg_match($regex, "") === false) {
            throw new \DomainException(
                "Deflate content types must be a valid regex"
            );
        }

        $this->deflateContentTypes = $regex;
    }

    private function setDeflateMinimumLength(int $bytes) {
        if ($bytes < 0) {
            throw new \DomainException(
                "Deflate minimum length must be greater than or equal to zero bytes"
            );
        }

        $this->deflateMinimumLength = $bytes;
    }

    private function setDeflateBufferSize(int $bytes) {
        if ($bytes <= 0) {
            throw new \DomainException(
                "Deflate buffer size must be greater than zero bytes"
            );
        }

        $this->deflateBufferSize = $bytes;
    }

    private function setChunkBufferSize(int $bytes) {
        if ($bytes <= 0) {
            throw new \DomainException(
                "Chunk buffer size must be greater than zero bytes"
            );
        }

        $this->chunkBufferSize = $bytes;
    }

    private function setOutputBufferSize(int $bytes) {
        if ($bytes <= 0) {
            throw new \DomainException(
                "Output buffer size must be greater than zero bytes"
            );
        }

        $this->outputBufferSize = $bytes;
    }

    private function setShutdownTimeout(int $milliseconds) {
        if ($milliseconds < 0) {
            throw new \DomainException(
                "Shutdown timeout size must be greater than or equal to zero"
            );
        }

        $this->shutdownTimeout = $milliseconds;
    }

    private function setConfigPath(string $config) {
        if ($this->configPath !== null) {
            throw new \LogicException(
                "Config path can't be altered at run-time"
            );
        }

        $this->configPath = $config;
    }
}
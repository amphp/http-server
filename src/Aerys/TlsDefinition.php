<?php

namespace Aerys;

class TlsDefinition {
    
    private $localCertFile;
    private $certPassphrase;
    private $allowSelfSigned = TRUE;
    private $verifyPeer = FALSE;
    private $ciphers = 'RC4-SHA:HIGH:!MD5:!aNULL:!EDH';
    private $disableCompression = TRUE;
    private $cryptoType = STREAM_CRYPTO_METHOD_TLS_SERVER;
    
    function __construct($localCertFile, $certPassphrase) {
        $this->localCertFile = $localCertFile;
        $this->certPassphrase = $certPassphrase;
    }
    
    function getCryptoType() {
        return $this->cryptoType;
    }
    
    function getContextOptions() {
        return ['ssl' => [
            'local_cert' => $this->localCertFile,
            'passphrase' => $this->certPassphrase,
            'allow_self_signed' => $this->allowSelfSigned,
            'verify_peer' => $this->verifyPeer,
            'ciphers' => $this->ciphers,
            'disable_compression' => $this->disableCompression
        ]];
    }
    
    function setOptions(array $options) {
        $available = [
            'allowSelfSigned',
            'verifyPeer',
            'ciphers',
            'disableCompression',
            'cryptoType'
        ];
        
        foreach ($options as $key => $value) {
            if (in_array($key, $available)) {
                $this->$key = $available;
            } else {
                throw new \DomainException(
                    'Invalid TLS option: ' . $key
                );
            }
        }
    }
    
}


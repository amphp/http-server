<?php

namespace Aerys;

class TlsDefinition {
    
    private $interface;
    private $port;
    private $localCertFile;
    private $certPassphrase;
    private $allowSelfSigned = FALSE;
    private $verifyPeer = FALSE;
    private $ciphers = 'RC4-SHA:HIGH:!MD5:!aNULL:!EDH';
    private $disableCompression = TRUE;
    private $cryptoType = STREAM_CRYPTO_METHOD_TLS_SERVER;
    
    function __construct($address, $localCertFile, $certPassphrase) {
        $this->parseAddress($address);
        $this->localCertFile = $localCertFile;
        $this->certPassphrase = $certPassphrase;
    }
    
    private function parseAddress($address) {
        if (FALSE === strpos($address, ':')) {
            throw new \InvalidArgumentException;
        }
        
        list($interface, $port) = explode(':', $address);
        
        $this->interface = ($interface == '*') ? '0.0.0.0' : $interface;
        $this->port = (int) $port;
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
    
    function getAddress() {
        return $this->interface . ':' . $this->port;
    }
    
    function getInterface() {
        return $this->interface;
    }
    
    function getPort() {
        return $this->port;
    }
    
    function getStreamContext() {
        return stream_context_create([
            'ssl' => [
                'local_cert' => $this->localCertFile,
                'passphrase' => $this->certPassphrase,
                'allow_self_signed' => $this->allowSelfSigned,
                'verify_peer' => $this->verifyPeer,
                'ciphers' => $this->ciphers,
                'disable_compression' => $this->disableCompression
            ]
        ]);
    }
    
    function getCryptoType() {
        return $this->cryptoType;
    }
    
}


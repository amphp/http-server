<?php

namespace Aerys;

class TlsDefinition {
    
    private $interface;
    private $port;
    private $localCertFile;
    private $certPassphrase;
    private $allowSelfSigned;
    private $verifyPeer;
    private $cyptoType;
    
    /**
     * STREAM_CRYPTO_METHOD_SSLv2_SERVER
     * STREAM_CRYPTO_METHOD_SSLv3_SERVER
     * STREAM_CRYPTO_METHOD_SSLv23_SERVER
     * STREAM_CRYPTO_METHOD_TLS_SERVER
     */
    function __construct($address, $localCertFile, $certPassphrase, $allowSelfSigned, $verifyPeer, $cyptoType = NULL) {
        $this->parseAddress($address);
        
        $this->localCertFile = $localCertFile;
        $this->certPassphrase = $certPassphrase;
        $this->allowSelfSigned = $allowSelfSigned;
        $this->verifyPeer = $verifyPeer;
        $this->cryptoType = $cyptoType ?: STREAM_CRYPTO_METHOD_TLS_SERVER;
    }
    
    /**
     * @todo determine appropriate exception to throw on bad address
     */
    private function parseAddress($address) {
        if (FALSE === strpos($address, ':')) {
            throw new \Exception;
        }
        
        list($interface, $port) = explode(':', $address);
        
        $this->interface = ($interface == '*') ? '0.0.0.0' : $interface;
        $this->port = (int) $port;
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
                'verify_peer' => $this->verifyPeer
            ],
            /*
            'ctx' => [
            
            ]
            */
        ]);
    }
    
    function getLocalCertFile() {
        return $this->localCertFile;
    }
    
    function getCertPassphrase() {
        return $this->certPassphrase;
    }
    
    function getAllowSelfSigned() {
        return $this->allowSelfSigned;
    }
    
    function getVerifyPeer() {
        return $this->verifyPeer;
    }
    
    function getCryptoType() {
        return $this->cryptoType;
    }
    
}


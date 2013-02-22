<?php

namespace Aerys;

use Aerys\Engine\EventBase;

class TlsServer extends Server {
    
    private $eventBase;
    private $clientsPendingHandshake = [];
    private $pendingClientCount = 0;
    private $cryptoType = STREAM_CRYPTO_METHOD_TLS_SERVER;
    private $handshakeTimeout = 3;
    private $context = [
        'local_cert' => NULL,
        'passphrase' => NULL,
        'allow_self_signed' => TRUE,
        'verify_peer' => FALSE,
        'ciphers' => 'RC4-SHA:HIGH:!MD5:!aNULL:!EDH',
        'disable_compression' => TRUE
    ];
    
    function __construct(EventBase $eventBase, $interface, $port, $localCert, $passphrase) {
        parent::__construct($eventBase, $interface, $port);
        
        $this->eventBase = $eventBase;
        $this->context['local_cert'] = $localCert;
        $this->context['passphrase'] = $passphrase;
    }
    
    function setOption($option, $value) {
        $optionMap = [
            'allowSelfSigned' => 'allow_self_signed',
            'verifyPeer' => 'verify_peer',
            'ciphers' => 'ciphers',
            'disableCompression' => 'disable_compression',
        ];
        
        if (isset($optionMap[$option])) {
            $contextKey = $optionMap[$option];
            $this->context[$contextKey] = $value;
        } elseif ($option == 'cryptoType') {
            $this->cryptoType = $value;
        } elseif ($option == 'handshakeTimeout') {
            $this->handshakeTimeout = (int) $value;
        } else {
            $this->context[$option] = $value;
        }
    }
    
    final protected function accept($socket, callable $onClient) {
        $serverName = stream_socket_get_name($socket, FALSE);
        
        while ($clientSock = @stream_socket_accept($socket, 0, $peerName)) {
            stream_context_set_option($clientSock, ['ssl' => $this->context]);
            
            $tlsEnabler = $this->eventBase->onReadable($clientSock, function ($clientSock, $trigger) {
                $this->doHandshake($clientSock, $trigger);
            }, $this->handshakeTimeout * 1000000);
            
            $clientId = (int) $clientSock;
            $this->clientsPendingHandshake[$clientId] = [$tlsEnabler, $clientName, $serverName, $onClient];
            $this->pendingClientCount++;
            $this->doHandshake($clientSock);
        }
    }
    
    /**
     * Note that the strict `FALSE ===` check against the crypto result is required because a falsy
     * zero integer value is returned when the handshake is still pending.
     */
    private function doHandshake($clientSock, $trigger) {
        if ($trigger == EventBase::TIMEOUT) {
            $this->failConnectionAttempt($clientSock);
        } elseif ($cryptoResult = @stream_socket_enable_crypto($clientSock, TRUE, $this->cryptoType)) {
            $clientId = (int) $clientSock;
            $pendingInfo = $this->clientsPendingHandshake[$clientId];
            list($tlsEnabler, $clientName, $serverName, $onClient) = $pendingInfo;
            
            $tlsEnabler->cancel();
            $this->pendingClientCount--;
            unset($this->clientsPendingHandshake[$clientId]);
            
            $onClient($clientSock, $peerName, $serverName);
            
        } elseif (FALSE === $cryptoResult) {
            $this->failConnectionAttempt($clientSock);
        }
    }
    
    private function failConnectionAttempt($clientSock) {
        $clientId = (int) $clientSock;
        $tlsEnabler = $this->clientsPendingHandshake[$clientId][0];
        $tlsEnabler->cancel();
        $this->pendingTlsClientCount--;
        
        fclose($clientSock);
        
        unset($this->clientsPendingHandshake[$clientId]);
    }
    
}


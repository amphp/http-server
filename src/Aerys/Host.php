<?php

namespace Aerys;

use Aerys\Mods\OnHeadersMod,
    Aerys\Mods\BeforeResponseMod,
    Aerys\Mods\AfterResponseMod;

class Host {
    
    private $address;
    private $port;
    private $name;
    private $handler;
    private $isTlsAvailable;
    private $tlsContext;
    private $tlsDefaults = [
        'local_cert'          => NULL,
        'passphrase'          => NULL,
        'allow_self_signed'   => FALSE,
        'verify_peer'         => FALSE,
        'ciphers'             => 'RC4-SHA:HIGH:!MD5:!aNULL:!EDH',
        'disable_compression' => TRUE,
        'cafile'              => NULL,
        'capath'              => NULL
    ];
    private $modPriorityMap;
    private $onHeadersMods = [];
    private $beforeResponseMods = [];
    private $afterResponseMods = [];
    private $defaultModPriorities = [
        'onHeaders' => 50,
        'beforeResponse' => 50,
        'afterResponse' => 50
    ];
    
    function __construct($address, $port, $name, callable $asgiAppHandler) {
        $this->setAddress($address);
        $this->setPort($port);
        $this->name = strtolower($name);
        $this->id = $this->name . ':' . $this->port;
        $this->handler = $asgiAppHandler;
        $this->isTlsAvailable = extension_loaded('openssl');
        $this->modPriorityMap = new \SplObjectStorage;
    }
    
    private function setAddress($address) {
        $address = trim($address, "[]");
        if ($address === '*') {
            $this->address = $address;
        } elseif ($address === '::') {
            $this->address = '[::]';
        } elseif (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $this->address = "[{$address}]";
        } elseif (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $this->address = $address;
        } else {
            throw new \InvalidArgumentException(
                "Valid IPv4/IPv6 address or wildcard required: {$address}"
            );
        }
    }
    
    private function setPort($port) {
        if ($port = filter_var($port, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 1,
            'max_range' => 65535
        ]])) {
            $this->port = (int) $port;
        } else {
            throw new \InvalidArgumentException(
                "Invalid host port: {$port}"
            );
        }
    }
    
    /**
     * Retrieve the ID for this host
     * 
     * @return string
     */
    function getId() {
        return $this->id;
    }
    
    /**
     * Retrieve the IP on which the host listens (may be a wildcard "*" or "[::]")
     * 
     * @return string
     */
    function getAddress() {
        return $this->address;
    }
    
    /**
     * Retrieve the port on which this host listens
     * 
     * @return int
     */
    function getPort() {
        return $this->port;
    }
    
    /**
     * Retrieve the host's name
     * 
     * @return string
     */
    function getName() {
        return $this->name;
    }
    
    /**
     * Retrieve the callable application handler for this host
     * 
     * @return mixed
     */
    function getHandler() {
        return $this->handler;
    }
    
    /**
     * Is this host's address defined by a wildcard character?
     * 
     * @return bool
     */
    function hasWildcardAddress() {
        return ($this->address === '*' || $this->address === '[::]');
    }
    
    /**
     * Does this host have a name?
     * 
     * @return bool
     */
    function hasName() {
        return ($this->name || $this->name === '0');
    }
    
    /**
     * Has this host been assigned a TLS encryption context?
     * 
     * @return bool Returns TRUE if a TLS context is assigned, FALSE otherwise
     */
    function isEncrypted() {
        return (bool) $this->tlsContext;
    }
    
    /**
     * Define TLS encryption settings for this host
     * 
     * @param array An array mapping TLS stream context values
     * @link http://php.net/manual/en/context.ssl.php
     * @throws \RuntimeException If required PHP OpenSSL extension not loaded
     * @throws \InvalidArgumentException On missing local_cert or passphrase keys
     * @return void
     */
    function setEncryptionContext(array $tlsDefinition) {
        if ($this->isTlsAvailable) {
            $this->tlsContext = $tlsDefinition ? $this->generateTlsContext($tlsDefinition) : NULL;
        } else {
            throw new \RuntimeException(
                sprintf('Cannot enable crypto on %s; openssl extension required', $this->id)
            );
        }
    }
    
    private function generateTlsContext(array $tls) {
        $tls = array_filter($tls, function($value) { return isset($value); });
        $tls = array_merge($this->tlsDefaults, $tls);
        
        if (empty($tls['local_cert'])) {
            throw new \InvalidArgumentException(
                '"local_cert" key required to bind crypto-enabled server socket'
            );
        } elseif (!(is_file($tls['local_cert']) && is_readable($tls['local_cert']))) {
            throw new \InvalidArgumentException(
                "Certificate file not found: {$tls['local_cert']}"
            );
        } elseif (empty($tls['passphrase'])) {
            throw new \InvalidArgumentException(
                '"passphrase" key required to bind crypto-enabled server socket'
            );
        }
        
        return stream_context_create(['ssl' => $tls]);
    }
    
    /**
     * Retrieve this host's socket connection context
     * 
     * @return resource The stream context to use when generating a server socket for this host
     */
    function getContext() {
        return $this->tlsContext ?: stream_context_create();
    }
    
    /**
     * Determine if this host matches the specified Host ID string
     * 
     * @param string $hostId
     * @return bool Returns TRUE if a match is found, FALSE otherwise
     */
    function matches($hostId) {
        if ($hostId === '*' || $hostId === $this->id) {
            $isMatch = TRUE;
        } elseif (substr($hostId, 0, 2) === '*:') {
            $portToMatch = substr($hostId, 2);
            $isMatch = ($portToMatch === '*' || $this->port == $portToMatch);
        } elseif (substr($hostId, -2) === ':*') {
            $addrToMatch = substr($hostId, 0, -2);
            $isMatch = ($addrToMatch === '*' || $this->address === $addrToMatch || $this->name === $addrToMatch);
        } else {
            $isMatch = FALSE;
        }
        
        return $isMatch;
    }
    
    /**
     * Register a mod for this host
     * 
     * @param mixed $mod
     * @param array $priorityMap An optional array mapping execution priorities. Valid keys are:
     *                           [onHeaders, beforeResponse, afterResponse]
     * @throws \DomainException On invalid priority map key
     * @throws \InvalidArgumentException On invalid mod parameter
     * @return void
     */
    function registerMod($mod, array $priorityMap = []) {
        if ($diff = array_diff_key($priorityMap, $this->defaultModPriorities)) {
            throw new \DomainException(
                'Invalid priority map key(s): ' . implode(', ', $diff)
            );
        } elseif (!($mod instanceof OnHeadersMod
            || $mod instanceof BeforeResponseMod
            || $mod instanceof AfterResponseMod
        )) {
            throw new \InvalidArgumentException(
                '$mod parameter at Argument 1 must implement a server mod interface'
            );
        }
        
        $priorityMap = array_merge($this->defaultModPriorities, $priorityMap);
        $this->modPriorityMap->attach($mod, $priorityMap);
        $this->sortModsByPriority();
    }
    
    private function sortModsByPriority() {
        $onHeadersMods = $beforeResponseMods = $afterResponseMods = [];
        
        foreach ($this->modPriorityMap as $mod) {
            $priorities = $this->modPriorityMap->offsetGet($mod);
            
            if ($mod instanceof OnHeadersMod) {
                $onHeadersMods[] = [$mod, $priorities['onHeaders']];
            }
            if ($mod instanceof BeforeResponseMod) {
                $beforeResponseMods[] = [$mod, $priorities['beforeResponse']];
            }
            if ($mod instanceof AfterResponseMod) {
                $afterResponseMods[] = [$mod, $priorities['afterResponse']];
            }
        }
        
        usort($onHeadersMods, [$this, 'prioritySort']);
        usort($beforeResponseMods, [$this, 'prioritySort']);
        usort($afterResponseMods, [$this, 'prioritySort']);
        
        $this->onHeadersMods = array_map('current', $onHeadersMods);
        $this->beforeResponseMods = array_map('current', $beforeResponseMods);
        $this->afterResponseMods = array_map('current', $afterResponseMods);
    }
    
    private function prioritySort(array $a, array $b) {
        return ($a[1] != $b[1]) ? ($a[1] - $b[1]) : 0;
    }
    
    /**
     * Retrieve an array of registered onHeaders Mod instances ordered by execution priority
     * 
     * @return array
     */
    function getOnHeadersMods() {
        return $this->onHeadersMods;
    }
    
    /**
     * Retrieve an array of registered beforeResponse Mod instances ordered by execution priority
     * 
     * @return array
     */
    function getBeforeResponseMods() {
        return $this->beforeResponseMods;
    }
    
    /**
     * Retrieve an array of registered afterResponse Mod instances ordered by execution priority
     * 
     * @return array
     */
    function getAfterResponseMods() {
        return $this->afterResponseMods;
    }
    
}

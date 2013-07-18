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
    private $tlsContext;
    private static $tlsDefaults = [
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
    private static $defaultModPriorities = [
        'mod.priority.onHeaders' => 50,
        'mod.priority.beforeResponse' => 50,
        'mod.priority.afterResponse' => 50
    ];
    
    function __construct($address, $port, $name, callable $asgiAppHandler) {
        $this->address = $address;
        $this->port = (int) $port;
        $this->name = strtolower($name);
        $this->handler = $asgiAppHandler;
        $this->modPriorityMap = new \SplObjectStorage;  
    }
    
    function getId() {
        return $this->name . ':' . $this->port;
    }
    
    function getAddress() {
        return $this->address;
    }
    
    function getPort() {
        return $this->port;
    }
    
    function getName() {
        return $this->name;
    }
    
    function getHandler() {
        return $this->handler;
    }
    
    function isWildcard() {
        return ($this->address === '*');
    }
    
    function registerTlsDefinition(array $tlsDefinition) {
        $this->tlsContext = $tlsDefinition ? $this->generateTlsContext($tlsDefinition) : NULL;
    }
    
    private function generateTlsContext(array $tls) {
        $tls = array_filter($tls, function($value) { return isset($value); });
        $tls = array_merge(self::$tlsDefaults, $tls);
        
        if (empty($tls['local_cert'])) {
            throw new \UnexpectedValueException(
                'TLS local_cert required to bind crypto-enabled server socket'
            );
        } elseif (empty($tls['passphrase'])) {
            throw new \UnexpectedValueException(
                'TLS passphrase required to bind crypto-enabled server socket'
            );
        }
        
        return stream_context_create(['ssl' => $tls]);
    }
    
    function hasTlsDefinition() {
        return (bool) $this->tlsContext;
    }
    
    function getTlsContext() {
        return $this->tlsContext ?: stream_context_create();
    }
    
    function registerMod($mod, array $priorityMap = []) {
        $priorityMap = array_intersect_key($priorityMap, self::$defaultModPriorities);
        $priorityMap = array_merge(self::$defaultModPriorities, $priorityMap);
        $this->modPriorityMap->attach($mod, $priorityMap);
        $this->sortModsByPriority();
    }
    
    private function sortModsByPriority() {
        $onHeadersMods = $beforeResponseMods = $afterResponseMods = [];
        
        foreach ($this->modPriorityMap as $mod) {
            $priorities = $this->modPriorityMap->offsetGet($mod);
            
            if ($mod instanceof OnHeadersMod) {
                $onHeadersMods[] = [$mod, $priorities['mod.priority.onHeaders']];
            }
            if ($mod instanceof BeforeResponseMod) {
                $beforeResponseMods[] = [$mod, $priorities['mod.priority.beforeResponse']];
            }
            if ($mod instanceof AfterResponseMod) {
                $afterResponseMods[] = [$mod, $priorities['mod.priority.afterResponse']];
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
    
    function getOnHeadersMods() {
        return $this->onHeadersMods;
    }
    
    function getBeforeResponseMods() {
        return $this->beforeResponseMods;
    }
    
    function getAfterResponseMods() {
        return $this->afterResponseMods;
    }
    
}

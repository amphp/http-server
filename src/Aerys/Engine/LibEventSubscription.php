<?php

namespace Aerys\Engine;

class LibEventSubscription implements Subscription {
    
    private $base;
    private $event;
    private $interval;
    private $status = self::ENABLED;
    
    function __construct(LibEventBase $base, $event, $interval) {
        $this->base = $base;
        $this->event = $event;
        $this->interval = $interval;
    }
    
    function cancel() {
        if ($this->status != self::CANCELLED) {
            $this->base->cancel($this);
            $this->status = self::CANCELLED;
        }
    }
    
    function enable() {
        if ($this->status == self::DISABLED) {
            event_add($this->event, $this->interval);
            $this->status = self::ENABLED;
        } elseif ($this->status == self::CANCELLED) {
            throw new \RuntimeException(
                'Cannot reenable a subscription once cancelled'
            );
        }
    }
    
    function disable() {
        if ($this->status == self::ENABLED) {
            event_del($this->event);
            $this->status = self::DISABLED;
        }
    }
    
    function status() {
        return $this->status;
    }
    
}


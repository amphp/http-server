<?php

namespace Aerys\Reactor;

class LibEventReactor implements Reactor {
    
    private $base;
    private $subscriptions;
    private $garbage = [];
    private $garbageCollectionInterval;
    private $resolution = 1000000;
    
    function __construct($gcInterval = NULL) {
        $this->base = event_base_new();
        $this->subscriptions = new \SplObjectStorage;
        
        $this->garbageCollectionInterval = $gcInterval
            ? $gcInterval * $this->resolution
            : 2 * $this->resolution;
        
        $garbageEvent = event_new();
        event_timer_set($garbageEvent, [$this, 'collectGarbage'], $garbageEvent);
        event_base_set($garbageEvent, $this->base);
        event_add($garbageEvent, $this->garbageCollectionInterval);
    }
    
    function getResolution() {
        return $this->resolution;
    }
    
    function tick() {
        event_base_loop($this->base, EVLOOP_ONCE | EVLOOP_NONBLOCK);
    }
    
    function run() {
        event_base_loop($this->base);
    }
    
    function stop() {
        event_base_loopexit($this->base);
    }
    
    function once($interval, callable $callback) {
        $event = event_new();
        
        $wrapper = function() use ($callback) {
            try {
                $callback();
            } catch (\Exception $e) {
                $this->stop();
                throw $e;
            }
        };
        
        event_timer_set($event, $wrapper);
        event_base_set($event, $this->base);
        event_add($event, $interval);
        
        $subscription = new LibEventSubscription($this, $event, $interval);
        $this->subscriptions->attach($subscription, $event);
        
        return $subscription;
    }
    
    function repeat($interval, callable $callback) {
        $event = event_new();
        
        $wrapper = function() use ($callback, $event, $interval) {
            try {
                $callback();
                event_add($event, $interval);
            } catch (\Exception $e) {
                $this->stop();
                throw $e;
            }
        };
        
        event_timer_set($event, $wrapper);
        event_base_set($event, $this->base);
        event_add($event, $interval);
        
        $subscription = new LibEventSubscription($this, $event, $interval);
        $this->subscriptions->attach($subscription, $event);
        
        return $subscription;
    }
    
    function onReadable($ioStream, callable $callback, $timeout = -1) {
        return $this->subscribe($ioStream, EV_READ | EV_PERSIST, $callback, $timeout);
    }
    
    function onWritable($ioStream, callable $callback, $timeout = -1) {
        return $this->subscribe($ioStream, EV_WRITE | EV_PERSIST, $callback, $timeout);
    }
    
    private function subscribe($ioStream, $flags, callable $callback, $timeout) {
        $event = event_new();
        
        $wrapper = function($ioStream, $triggeredBy) use ($callback) {
            try {
                $callback($ioStream, $triggeredBy);
            } catch (\Exception $e) {
                $this->stop();
                throw $e;
            }
        };
        
        event_set($event, $ioStream, $flags, $wrapper);
        event_base_set($event, $this->base);
        event_add($event, $timeout);
        
        $subscription = new LibEventSubscription($this, $event, $timeout);
        $this->subscriptions->attach($subscription);
        
        return $subscription;
    }
    
    /**
     * Sometimes it's desirable to cancel a subscription from within an event callback. We can't
     * destroy lambda callbacks inside cancel() from inside a subscribed event callback, so instead
     * we store the cancelled subscription in the garbage periodically clean up after ourselves.
     */
    function cancel(Subscription $subscription) {
        $subscription->disable();
        $this->subscriptions->detach($subscription);
        $this->garbage[] = $subscription;
    }
    
    private function collectGarbage($nullFd, $flags, $garbageEvent) {
        $this->garbage = [];
        event_add($garbageEvent, $this->garbageCollectionInterval);
    }
    
}


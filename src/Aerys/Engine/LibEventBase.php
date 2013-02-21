<?php

namespace Aerys\Engine;

class LibEventBase implements EventBase {
    
    const GARBAGE_COLLECT_INTERVAL = 2000000;
    
    private $base;
    private $subscriptions;
    private $garbage = [];
    
    function __construct() {
        $this->base = event_base_new();
        $this->subscriptions = new \SplObjectStorage;
        
        $garbageEvent = event_new();
        event_timer_set($garbageEvent, [$this, 'collectGarbage'], $garbageEvent);
        event_base_set($garbageEvent, $this->base);
        event_add($garbageEvent, self::GARBAGE_COLLECT_INTERVAL);
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
            // @todo add bitwise check to determine READ/WRITE/TIMEOUT for $triggeredBy
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
     * we store the cancelled subscription in the garbage and collect it periodically.
     */
    function cancel(Subscription $subscription) {
        $subscription->disable();
        $this->subscriptions->detach($subscription);
        $this->garbage[] = $subscription;
    }
    
    private function collectGarbage($nullFd, $flags, $garbageEvent) {
        $this->garbage = [];
        event_add($garbageEvent, self::GARBAGE_COLLECT_INTERVAL);
    }
    
}


<?php

use Amp\Reactor;

class ChatMediator {
    
    const MESSAGE_STACK_SIZE = 10;
    
    private $reactor;
    private $subscribers = [
        'hello' => [],
        'message' => [],
        'goodbye' => []
    ];
    private $lastUserId = 0;
    private $cachedUserCount = 0;
    private $messageStack = [];
    
    function __construct(Reactor $reactor) {
        $this->reactor = $reactor;
    }
    
    function registerUser() {
        $userId = ++$this->lastUserId;
        $this->cachedUserCount++;
        
        if ($this->lastUserId === PHP_INT_MAX) {
            $this->lastUserId = 0;
        }
        
        $this->reactor->immediately(function() use ($userId) {
            $this->doHello($userId);
        });
        
        return $userId;
    }
    
    private function doHello($userId) {
        foreach ($this->subscribers['hello'] as $subscriber) {
            $subscriber($userId);
        }
    }
    
    function broadcast($userId, $msg) {
        if (array_unshift($this->messageStack, $msg) > self::MESSAGE_STACK_SIZE) {
            array_pop($this->messageStack);
        }
        
        $this->reactor->immediately(function() use ($userId, $msg) {
            $this->doMessage($userId, $msg);
        });
    }
    
    private function doMessage($userId, $msg) {
        foreach ($this->subscribers['message'] as $subscriber) {
            $subscriber($userId, $msg);
        }
    }
    
    function disconnect($userId) {
        $this->cachedUserCount--;
        $this->reactor->immediately(function() use ($userId) {
            $this->doGoodbye($userId);
        });
    }
    
    private function doGoodbye($userId) {
        foreach ($this->subscribers['goodbye'] as $subscriber) {
            $subscriber($userId);
        }
    }
    
    function fetchCount() {
        return $this->cachedUserCount;
    }
    
    function fetchRecent() {
        return $this->messageStack;
    }
    
    function subscribe($event, callable $subscriber) {
        if (isset($this->subscribers[$event])) {
            $this->subscribers[$event][] = $subscriber;
        } else {
            throw new \DomainException(
                "Unknown subscription event: {$event}"
            );
        }
    }
}

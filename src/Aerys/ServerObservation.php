<?php

namespace Aerys;

class ServerObservation {

    private $event;
    private $callback;
    private $priority = 50;
    private $host = '*';
    private static $validEvents = [
        Server::STOPPED,
        Server::STARTED,
        Server::ON_HEADERS,
        Server::BEFORE_RESPONSE,
        Server::AFTER_RESPONSE,
        Server::PAUSED,
        Server::STOPPING,
        Server::NEED_STOP_PERMISSION
    ];

    function __construct($event, callable $callback, array $options = []) {
        $this->setEvent($event);
        $this->callback = $callback;
        if ($options) {
            $this->setOptions($options);
        }
    }

    private function setEvent($event) {
        if (in_array($event, self::$validEvents)) {
            $this->event = $event;
        } else {
            throw new \DomainException(
                sprintf('Unrecognized observable server event: %s', $event)
            );
        }
    }

    private function setOptions(array $options) {
        if (isset($options['priority'])) {
            $this->setPriority($options['priority']);
        }
        if (isset($options['host'])) {
            $this->host = $options['host'];
        }
    }

    private function setPriority($priority) {
        $this->priority = filter_var($priority, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'max_range' => 100,
            'default' => 50
        ]]);
    }

    function getEvent() {
        return $this->event;
    }

    function getCallback() {
        return $this->callback;
    }

    function getHost() {
        return $this->host;
    }

    function getPriority() {
        return $this->priority;
    }
}

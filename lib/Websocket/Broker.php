<?php

namespace Aerys\Websocket;

class Broker {
    private $endpoint;

    public function __construct(Endpoint $endpoint) {
        $this->endpoint = $endpoint;
    }

    public function send($string, $recipient) {
        if (is_string($string) && isset($string[0])) {
            $this->endpoint->send($string, [$recipient]);
        }
    }

    public function broadcast($string, array $recipients = []) {
        if (is_string($string) && isset($string[0])) {
            $this->endpoint->send($string, $recipients);
        }
    }

    public function close($socketId, $code = Codes::NORMAL_CLOSE, $reason = '') {
        $this->endpoint->close($socketId, $code, $reason);
    }

    public function stats($socketId) {
        return $this->endpoint->getStats($socketId);
    }
}

<?php

namespace Aerys;

use Amp\{
    function any,
    function resolve,
    Reactor,
    Success,
    Failure,
    Promise,
    PrivateFuture,
    Struct
};

class Server implements \SplSubject {
    use Struct;

    const STOPPED   = 0b000;
    const STARTING  = 0b001;
    const STARTED   = 0b010;
    const STOPPING  = 0b100;

    private $state = self::STOPPED;
    private $reactor;
    private $observers;
    private $acceptor;
    private $acceptWatcherIds = [];
    private $boundAddresses = [];

    /**
     * @param \Amp\Reactor $reactor
     */
    public function __construct(Reactor $reactor) {
        $this->reactor = $reactor;
        $this->observers = new \SplObjectStorage;
        $this->acceptor = function($reactor, $watcherId, $server, $onClient) {
            if ($client = @stream_socket_accept($server, 0)) {
                ($onClient)($client);
            }
        };
    }

    /**
     * Start the server using multiple listening sockets
     *
     * @param array $addressContextMap An array of the form ["<bind address>" => $streamContext]
     * @param callable $onClient A callback to invoke when new client sockets are accepted
     * @return \Amp\Promise
     */
    public function start(array $addressContextMap, callable $onClient): Promise {
        try {
            switch ($this->state) {
                case self::STOPPED:
                    break;
                case self::STARTING:
                    return new Failure(new \LogicException(
                        "Cannot start server: already STARTING"
                    ));
                case self::STARTED:
                    return new Failure(new \LogicException(
                        "Cannot start server: already STARTED"
                    ));
                case self::STOPPING:
                    return new Failure(new \LogicException(
                        "Cannot start server: already STOPPING"
                    ));
                default:
                    return new Failure(new \LogicException(
                        sprintf("Unexpected server state encountered: %s", $this->state)
                    ));
            }
            if (empty($addressContextMap)) {
                return new Failure(new \LogicException(
                    "Cannot start: empty address array at Argument 2"
                ));
            }
            $serverStreams = [];
            foreach ($addressContextMap as $address => $context) {
                $serverStreams[$address] = $this->bind($address, $context);
            }

            return resolve($this->doStart($serverStreams, $onClient), $this->reactor);

        } catch (\BaseException $e) {
            return new Failure($e);
        }
    }

    private function bind(string $address, $context) {
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        if (!$socket = stream_socket_server($address, $errno, $errstr, $flags, $context)) {
            throw new \RuntimeException(
                sprintf(
                    "Failed binding socket on %s: [Err# %s] %s",
                    $address,
                    $errno,
                    $errstr
                )
            );
        }

        return $socket;
    }

    private function doStart(array $serverStreams, callable $onClient): \Generator {
        $this->state = self::STARTING;
        $notifyResult = yield $this->notify();
        if ($hadErrors = $notifyResult[0]) {
            yield from $this->doStop();
            throw new \RuntimeException(
                "Server STARTING observer initialization failure"
            );
        }

        $this->state = self::STARTED;

        foreach ($serverStreams as $address => $server) {
            $this->boundAddresses[] = substr(str_replace('0.0.0.0', '*', $address), 6);
        }

        $notifyResult = yield $this->notify();
        if ($hadErrors = $notifyResult[0]) {
            yield from $this->doStop();
            throw new \RuntimeException(
                "Server STARTING observer initialization failure"
            );
        }

        foreach ($serverStreams as $server) {
            $this->acceptWatcherIds[] = $this->reactor->onReadable($server, $this->acceptor, [
                "enable" => true,
                "cb_data" => $onClient,
            ]);
        }
    }

    /**
     * Stop the server
     *
     * @return \Amp\Promise
     */
    public function stop(): Promise {
        switch ($this->state) {
            case self::STARTED:
                return resolve($this->doStop(), $this->reactor);
            case self::STOPPED:
                return new Success;
            case self::STOPPING:
                return new Failure(new \LogicException(
                    "Cannot stop server: currently STOPPING"
                ));
            case self::STARTING:
                return new Failure(new \LogicException(
                    "Cannot stop server: currently STARTING"
                ));
            default:
                assert(false, sprintf("Unexpected server state encountered: %s", $this->state));
        }
    }

    private function doStop(): \Generator {
        foreach ($this->acceptWatcherIds as $watcherId) {
            $this->reactor->cancel($watcherId);
        }
        $this->acceptWatcherIds = [];
        $this->state = self::STOPPING;
        yield $this->notify();
        $this->boundAddresses = [];
        $this->state = self::STOPPED;
        yield $this->notify();
    }

    /**
     * Attach a server observer
     *
     * @param \SplObserver $observer
     * @return void
     */
    public function attach(\SplObserver $observer) {
        $this->observers->attach($observer);
    }

    /**
     * Detach an observer from the server
     *
     * @param \SplObserver $observer
     * @return void
     */
    public function detach(\SplObserver $observer) {
        $this->observers->detach($observer);
    }

    /**
     * Notify observers of a server state change
     *
     * Resolves to an indexed any() promise combinator array.
     *
     * @return \Amp\Promise
     */
    public function notify(): Promise {
        $promises = [];
        foreach ($this->observers as $observer) {
            $promises[] = $observer->update($this);
        }

        $promise = any($promises);
        $promise->when(function($error, $result) {
            // $error is always empty because an any() combinator promise never fails.
            // Instead we check the error array at index zero in the two-item amy() $result
            // and log as needed.
            list($observerErrors) = $result;
            if ($observerErrors) {
                foreach ($observerErrors as $error) {
                    error_log($error->__toString());
                }
            }
        });

        return $promise;
    }

    /**
     * Retrieve the current server state
     *
     * @return int
     */
    public function state(): int {
        return $this->state;
    }

    /**
     * Retrieve an array of currently bound socket addresses
     *
     * @return array
     */
    public function inspect(): array {
        switch ($this->state) {
            case self::STOPPED:  $state = "STOPPED";  break;
            case self::STARTING: $state = "STARTING"; break;
            case self::STARTED:  $state = "STARTED";  break;
            case self::STOPPING: $state = "STOPPING"; break;
        }

        return [
            "state" => $state,
            "boundAddresses" => $this->boundAddresses,
        ];
    }
}

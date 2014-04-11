<?php

namespace Aerys\Watch;

use Alert\Reactor,
    Alert\ReactorFactory;

class IpcBroker {

    const FRONTEND = 1;
    const BACKEND = 2;
    const OP_SEND = 3;
    const OP_BROADCAST = 4;
    const OP_CLOSE = 5;
    const OP_CLOSE_ALL = 6;

    private $reactor;
    private $backend;
    private $backendUri;
    private $frontend;
    private $frontendUri;
    private $allowControlConsole = TRUE;
    private $hasUnixBackendSocket;

    function __construct(Reactor $reactor = NULL, IpcServer $backend = NULL, IpcServer $frontend = NULL) {
        $this->reactor = $reactor ?: (new ReactorFactory)->select();
        $this->backend = $backend ?: new IpcServer;
        $this->frontend = $frontend ?: new IpcServer;
    }

    /**
     * Configure the backend or frontend IPC server
     *
     * @param int $targetId Either IpcBroker::BACKEND or IpcBroker::FRONTEND
     * @param array $eventCallbackMap An array mapping IPC event names to callbacks
     * @return void
     */
    function setIpcEventCallbacks($targetId, array $eventCallbackMap) {
        $target = $this->getTargetFromId($targetId);
        foreach ($eventCallbackMap as $eventName => $callback) {
            $target->setCallback($eventName, $callback);
        }
    }

    private function getTargetFromId($targetId) {
        switch ($targetId) {
            case self::BACKEND:
                $target = $this->backend;
                break;
            case self::FRONTEND:
                $target = $this->frontend;
                break;
            default:
                throw new \DomainException;
        }

        return $target;
    }

    /**
     * Perform an IPC operation on either the backend or frontend IPC server target
     *
     * @param int $targetId Either IpcBroker::BACKEND or IpcBroker::FRONTEND
     * @param int $opcode IpcBroker::OP_SEND, IpcBroker::OP_BROADCAST, IpcBroker::OP_CLOSE, IpcBroker::OP_CLOSE_ALL
     * @param array $args Optional operation arguments
     * @return void
     */
    function operate($targetId, $opcode, array $args = []) {
        $target = $this->getTargetFromId($targetId);
        $method = $this->selectTargetOperation($opcode);
        call_user_func_array([$target, $method], $args);
    }

    private function selectTargetOperation($opcode) {
        switch ($opcode) {
            case self::OP_SEND:
                $method = 'send';
                break;
            case self::OP_BROADCAST:
                $method = 'broadcast';
                break;
            case self::OP_CLOSE:
                $method = 'close';
                break;
            case self::OP_CLOSE_ALL:
                $method = 'closeAll';
                break;
            default:
                throw new \DomainException;
        }

        return $method;
    }

    /**
     * @TODO Add docblock
     * @TODO use filter_var for validation
     */
    function setFrontendPort($port) {
        $this->frontendUri = 'tcp://0.0.0.0:' . $port;
    }

    /**
     * Bind the backend/frontend IPC socket(s)
     *
     * @return void
     */
    function start() {
        $backendUri = $this->generateBackendUri();
        $this->backendUri = $this->backend->start($this->reactor, $backendUri);

        if ($this->frontendUri) {
            $this->frontend->start($this->reactor, $this->frontendUri);
        }
    }

    /**
     * Start the IPC event reactor and assume program flow control
     *
     * @return void
     */
    function run() {
        $this->reactor->run();
    }

    private function generateBackendUri() {
        if (in_array('unix', stream_get_transports())) {
            $this->hasUnixBackendSocket = TRUE;
            $uri = sprintf('unix://%s/%s', sys_get_temp_dir(), uniqid(TRUE));
        } else {
            $this->hasUnixBackendSocket = FALSE;
            $uri = 'tcp://127.0.0.1:9381';
        }

        return $uri;
    }

    /**
     * Temporarily pause IPC operation (used before forking)
     *
     * @return void
     */
    function pause() {
        $this->backend->pause();
        $this->frontend->pause();
    }

    /**
     * Resume paused IPC operation (used after forking)
     *
     * @return void
     */
    function resume() {
        $this->backend->resume();
        $this->frontend->resume();
    }

    /**
     * Shutdown backend and frontend IPC servers
     *
     * @param callable $onShutdown A callback to notify on shutdown completion
     * @return void
     */
    function shutdown(callable $onShutdown) {
        $this->backend->shutdown(function() use ($onShutdown) {
            $this->onBackendShutdown($onShutdown);
        });
    }

    private function onBackendShutdown(callable $onShutdown) {
        $this->frontend->broadcast('> System shutting down; goodbye!');
        $this->frontend->shutdown(function() use ($onShutdown) {
            $onShutdown();
        });
    }

    /**
     * Is the backend IPC server operating on a unix domain socket?
     *
     * @return bool
     */
    function hasUnixBackendSocket() {
        return $this->hasUnixBackendSocket;
    }

    /**
     * Retrieve the backend IPC server URI
     *
     * @return string
     */
    function getBackendUri() {
        return $this->backendUri;
    }

}

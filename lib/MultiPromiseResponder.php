<?php

namespace Aerys;

use Amp\Promise;

class MultiPromiseResponder implements Responder {
    private $multiApp;
    private $nextAppIndex;
    private $request;
    private $environment;
    private $responder;
    private $hasSocketControl;

    public function __construct(MultiApplication $multiApp, $nextAppIndex, array $request, Promise $promise) {
        $this->multiApp = $multiApp;
        $this->nextAppIndex = $nextAppIndex;
        $this->request = $request;
        $promise->when([$this, 'onResolution']);
    }

    /**
     * A callback to invoke when the originating Promise resolves
     *
     * @param \Exception $error
     * @param mixed $result
     */
    public function onResolution(\Exception $error = null, $result = null) {
        $ah = $this->multiApp;

        $this->responder = $responder = ($error)
            ? $ah->makeErrorResponder($error)
            : $ah->makeResponderFromHandlerResult($this->request, $result, $this->nextAppIndex);

        if ($this->environment) {
            $responder->prepare($this->environment);
        }

        if ($this->hasSocketControl) {
            $responder->assumeSocketControl();
        }
    }

    /**
     * Prepare the Responder for client output
     *
     * @param ResponderEnvironment $environment
     * @return void
     */
    public function prepare(ResponderEnvironment $env) {
        if ($this->responder) {
            $this->responder->prepare($env);
        } else {
            $this->environment = $env;
        }
    }

    /**
     * Assume control of the client socket and output the prepared response
     *
     * If we've already generated a Responder we start writing now. Otherwise set the control
     * flag so we know to start output when the Promise eventually resolves
     *
     * @return void
     */
    public function assumeSocketControl() {
        if ($this->responder) {
            $this->responder->assumeSocketControl();
        } else {
            $this->hasSocketControl = true;
        }
    }

    /**
     * Delegate write() calls to the Responder we eventually created from the resolved Promise
     *
     * @return void
     */
    public function write() {
        $this->responder->write();
    }
}

<?php

namespace Aerys\Framework;

use Alert\Reactor;

class ProcessWatcher implements ServerWatcher {

    private $reactor;
    private $cmd;
    private $processPipe;
    private $readWatcher;
    private $isChildReady = NULL;

    function __construct(Reactor $reactor, $workerBinary) {
        $this->reactor = $reactor;
        $this->cmd = $this->generateWorkerCmd($workerBinary);
    }

    private function generateWorkerCmd($cmd) {
        $parts = [];
        $parts[] = PHP_BINARY;
        if ($ini = get_cfg_var('cfg_file_path')) {
            $parts[] = "-c $ini";
        }
        $parts[] = $cmd;

        return implode(' ', $parts);
    }

    /**
     * Monitor a server running inside a child process and restart in the event of a fatal error
     *
     * @return void
     */
    function watch() {
        if (!$this->processPipe = popen($this->cmd, $mode = 'r')) {
            throw new \RuntimeException(
                sprintf('Failed spawning worker process: %s', $this->cmd)
            );
        }

        $this->readWatcher = $this->reactor->onReadable($this->processPipe, function() {
            $this->readFromWorkerStdout();
        });

        $this->reactor->run();
    }

    private function readFromWorkerStdout() {
        $data = @fgets($this->processPipe);

        if ($data) {
            $this->handleWorkerStdoutData($data);
        } elseif (!is_resource($this->processPipe) || @feof($this->processPipe)) {
            $this->handleDeadWorker();
        }
    }

    private function handleWorkerStdoutData($data) {
        if ($this->isChildReady) {
            fwrite(STDOUT, $data);
        } elseif ($this->isChildReady === NULL && trim($data) === 'ready') {
            $this->isChildReady = TRUE;
        } else {
            $this->isChildReady = FALSE;
            fwrite(STDOUT, $data);
        }
    }

    private function handleDeadWorker() {
        $this->kill();
        if ($this->isChildReady) {
            $this->watch();
        } else {
            throw new \RuntimeException(
                'Worker process died prior to successful server boot :('
            );
        }
    }

    private function kill() {
        if ($this->readWatcher) {
            $this->reactor->cancel($this->readWatcher);
        }

        if ($this->processPipe) {
            @pclose($this->processPipe);
        }
    }

    function __destruct() {
        $this->kill();
    }

}

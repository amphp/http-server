<?php

namespace Aerys;

use League\CLImate\CLImate;

/**
 * This class solely exists to wrap CLImate to make our code
 * more testable ... otherwise we have a mess of LoD violations
 * due to CLImate's exposure public property behavior objects.
 */
class Console {
    private $climate;
    private $hasParsedArgs;

    public function __construct(CLImate $climate) {
        $this->climate = $climate;
    }

    public function output(string $msg) {
        return $this->climate->out($msg);
    }

    public function forceAnsiOn() {
        $this->climate->forceAnsiOn();
    }

    public function isArgDefined(string $arg) {
        if (empty($this->hasParsedArgs)) {
            $this->parseArgs();
        }

        return $this->climate->arguments->defined($arg);
    }

    public function getArg(string $arg) {
        if (empty($this->hasParsedArgs)) {
            $this->parseArgs();
        }

        return $this->climate->arguments->get($arg);
    }

    private function parseArgs() {
        if (empty($this->hasParsedArgs)) {
            @$this->climate->arguments->parse();
            $this->hasParsedArgs = true;
        }
    }
}

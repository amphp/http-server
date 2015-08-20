<?php

namespace Aerys;

final class ConsoleLogger extends Logger {
    private $console;

    public function __construct(Console $console) {
        $this->console = $console;
        if ($console->isArgDefined("color")) {
            $value = $console->getArg("color");
            $this->setAnsiColorOption($value);
        }
        if ($console->isArgDefined("log")) {
            $level = $console->getArg("log");
            $level = isset(self::LEVELS[$level]) ? self::LEVELS[$level] : $level;
            $this->setOutputLevel($level);
        }
    }

    private function setAnsiColorOption($value) {
        $value = ($value === "") ? "on" : $value;
        $this->setAnsify($value);
        if ($value === "on") {
            $this->console->forceAnsiOn();
        }
    }

    final protected function output(string $message) {
        $this->console->output($message);
    }
}

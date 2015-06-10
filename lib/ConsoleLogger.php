<?php

namespace Aerys;

final class ConsoleLogger extends Logger {
    private $console;

    public function __construct(Console $console) {
        $this->console = $console;
        if ($console->isArgDefined("color")) {
            $this->setAnsify($console->getArg("color"));
        }
        if ($console->isArgDefined("log")) {
            $level = $console->getArg("log");
            $level = isset(self::LEVELS[$level]) ? self::LEVELS[$level] : $level;
            $this->setOutputLevel($level);
        }
    }

    final protected function output(string $message) {
        $this->console->output($message);
    }
}

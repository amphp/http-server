<?php

namespace Aerys;

use League\CLImate\CLImate;

final class ConsoleLogger extends Logger {
    private $climate;
    private $ansi = true;

    public function __construct(CLImate $climate) {
        $this->climate = $climate;

        if ($climate->arguments->defined("color")) {
            $logger->setAnsi($climate->arguments->get("color"));
        }
        if ($climate->arguments->defined("log")) {
            $level = $climate->arguments->get("log");
            $level = isset(self::LEVELS[$level])
                ? self::LEVELS[$level]
                : $level;
        } else {
            $level = self::LEVELS[self::DEBUG];
        }
        $this->setOutputLevel($level);
    }

    private function setAnsi(string $mode) {
        switch ($mode) {
            case "auto":
            case "on":
                $this->ansi = true;
                break;
            case "off":
                $this->ansi = false;
                break;
            default:
                $this->ansi = true;
                break;
        }
    }

    final protected function doLog($level, $message, array $context = []) {
        $time = @date("H:i:s", $context["time"] ?? time());
        $level = isset(self::LEVELS[$level]) ? $level : "unknown";
        $level = $this->ansi ? $this->generateAnsiLevel($level) : $level;
        $message = "[{$time}] {$level} {$message}";
        $this->climate->out($message);
    }

    private function generateAnsiLevel($level) {
        switch ($level) {
            case self::EMERGENCY:
            case self::ALERT:
            case self::CRITICAL:
            case self::ERROR:
                return "<bold><red>{$level}</red></bold>";
            case self::WARNING:
                return "<bold><yellow>{$level}</yellow></bold>";
            case self::NOTICE:
                return "<bold><green>{$level}</green></bold>";
            case self::INFO:
                return "<bold><magenta>{$level}</magenta></bold>";
            case self::DEBUG:
                return "<bold><cyan>{$level}</cyan></bold>";
        }
    }
}

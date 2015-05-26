<?php

namespace Aerys;

use League\CLImate\CLImate;
use Psr\Log\{
    LogLevel,
    LoggerInterface as Logger
};

class DebugLogger implements Logger {
    private $climate;
    public function __construct(CLImate $climate) {
        $this->climate = $climate;
    }
    public function emergency($message, array $context = []) {
        $this->log(LogLevel::EMERGENCY, $message);
    }
    public function alert($message, array $context = []) {
        $this->log(LogLevel::ALERT, $message);
    }
    public function critical($message, array $context = []) {
        $this->log(LogLevel::CRITICAL, $message);
    }
    public function error($message, array $context = []) {
        $this->log(LogLevel::ERROR, $message);
    }
    public function warning($message, array $context = []) {
        $this->log(LogLevel::WARNING, $message);
    }
    public function notice($message, array $context = []) {
        $this->log(LogLevel::NOTICE, $message);
    }
    public function info($message, array $context = []) {
        $this->log(LogLevel::INFO, $message);
    }
    public function debug($message, array $context = []) {
        $this->log(LogLevel::DEBUG, $message);
    }
    public function log($level, $message, array $context = []) {
        $time = date("H:i:s", $context["time"] ?? time());
        $level = $this->generateColoredLevel($level);
        $message = "[<light_gray>{$time}</light_gray>] {$level} {$message}";
        $this->climate->out($message);
    }
    private function generateColoredLevel($level) {
        switch ($level) {
            case LogLevel::EMERGENCY:
            case LogLevel::ALERT:
            case LogLevel::CRITICAL:
                return "<red><bold>{$level}:</bold></red>";
            case LogLevel::ERROR:
            case LogLevel::WARNING:
                return "<yellow><bold>{$level}:</bold></yellow>";
            case LogLevel::NOTICE:
            case LogLevel::INFO:
            case LogLevel::DEBUG:
                return "<green>{$level}:</green>";
            default:
                $this->climate(LogLevel::ERROR, "Unexpected log level ({$level})");
                return "";
        }
    }
}

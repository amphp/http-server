<?php

namespace Aerys;

use Psr\Log\{
    LogLevel as PsrLogLevel,
    LoggerInterface as PsrLogger
};

abstract class Logger implements PsrLogger {
    const DEBUG = PsrLogLevel::DEBUG;
    const INFO = PsrLogLevel::INFO;
    const NOTICE = PsrLogLevel::NOTICE;
    const WARNING = PsrLogLevel::WARNING;
    const ERROR = PsrLogLevel::ERROR;
    const CRITICAL = PsrLogLevel::CRITICAL;
    const ALERT = PsrLogLevel::ALERT;
    const EMERGENCY = PsrLogLevel::EMERGENCY;
    const LEVELS = [
        self::DEBUG => 8,
        self::INFO => 7,
        self::NOTICE => 6,
        self::WARNING => 5,
        self::ERROR => 4,
        self::CRITICAL => 3,
        self::ALERT => 2,
        self::EMERGENCY => 1,
    ];

    private $outputLevel = self::LEVELS[self::DEBUG];
    private $ansify = true;

    abstract protected function output(string $message);

    final public function emergency($message, array $context = []) {
        return $this->log(self::EMERGENCY, $message, $context);
    }

    final public function alert($message, array $context = []) {
        return $this->log(self::ALERT, $message, $context);
    }

    final public function critical($message, array $context = []) {
        return $this->log(self::CRITICAL, $message, $context);
    }

    final public function error($message, array $context = []) {
        return $this->log(self::ERROR, $message, $context);
    }

    final public function warning($message, array $context = []) {
        return $this->log(self::WARNING, $message, $context);
    }

    final public function notice($message, array $context = []) {
        return $this->log(self::NOTICE, $message, $context);
    }

    final public function info($message, array $context = []) {
        return $this->log(self::INFO, $message, $context);
    }

    final public function debug($message, array $context = []) {
        return $this->log(self::DEBUG, $message, $context);
    }

    final public function log($level, $message, array $context = []) {
        if ($this->canEmit($level)) {
            $message = $this->format($level, $message, $context);
            return $this->output($message);
        }
    }

    private function format($level, $message, array $context = []) {
        $time = @date("Y-m-d H:i:s", $context["time"] ?? time());
        $level = isset(self::LEVELS[$level]) ? $level : "unknown";
        $level = $this->ansify ? $this->ansify($level) : $level;

        foreach ($context as $key => $replacement) {
            // avoid invalid casts to string
            if (!is_array($replacement) && (!is_object($replacement) || method_exists($replacement, '__toString'))) {
                $replacements["{{$key}}"] = $replacement;
            }
        }
        if (isset($replacements)) {
            $message = strtr($message, $replacements);
        }

        return "[{$time}] {$level} {$message}";
    }

    private function ansify($level) {
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

    private function canEmit(string $logLevel) {
        return isset(self::LEVELS[$logLevel])
            ? ($this->outputLevel >= self::LEVELS[$logLevel])
            : false;
    }

    final protected function setOutputLevel(int $outputLevel) {
        if ($outputLevel < min(self::LEVELS)) {
            $outputLevel = min(self::LEVELS);
        } elseif ($outputLevel > max(self::LEVELS)) {
            $outputLevel = max(self::LEVELS);
        }

        $this->outputLevel = $outputLevel;
    }

    final protected function setAnsify(string $mode) {
        switch ($mode) {
            case "auto":
            case "on":
                $this->ansify = true;
                break;
            case "off":
                $this->ansify = false;
                break;
            default:
                $this->ansify = true;
                break;
        }
    }
}

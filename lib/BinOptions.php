<?php

namespace Aerys;

class BinOptions {
    private $help;
    private $debug;
    private $config;
    private $workers;
    private $control;
    private $shortOpts = 'hdc:w:z:';
    private $longOpts = [
        'help',
        'debug',
        'config:',
        'workers:',
        'control:',
    ];
    private $shortOptNameMap = [
        'h' => 'help',
        'd' => 'debug',
        'c' => 'config',
        'w' => 'workers',
        'z' => 'control',
    ];

    /**
     * Load command line options that may be used to bootstrap a server
     *
     * @param array $options Used if defined, loaded from the CLI otherwise
     * @throws Aerys\BootException
     * @return Aerys\BinOptions Returns the current object instance
     */
    public function loadOptions(array $options = []) {
        $rawOptions = $options ? $options : getopt($this->shortOpts, $this->longOpts);

        $normalizedOptions = [
            'help' => NULL,
            'config' => NULL,
            'workers' => NULL,
            'backend' => NULL,
        ];

        foreach ($rawOptions as $key => $value) {
            if (isset($this->shortOptNameMap[$key])) {
                $normalizedOptions[$this->shortOptNameMap[$key]] = $value;
            } else {
                $normalizedOptions[$key] = $value;
            }
        }

        $this->setOptionValues($normalizedOptions);

        if (!($this->help || $this->config)) {
            throw new BootException(
                'App config file (-c, --config) required; use -h or --help for more information.'
            );
        }

        return $this;
    }

    private function setOptionValues(array $options) {
        foreach ($options as $key => $value) {
            switch ($key) {
                case 'help':
                    $this->help = isset($value) ? TRUE : NULL;
                    break;
                case 'debug':
                    $this->debug = isset($value) ? TRUE : NULL;
                    break;
                case 'config':
                    $this->config = $value;
                    break;
                case 'workers':
                    $this->setWorkers($value);
                    break;
                case 'control':
                    $this->control = $value;
                    break;
            }
        }
    }

    private function setWorkers($count) {
        $count = @intval($count);
        $count = ($count >= 0) ? $count : 0;
        $this->workers = $count;
    }

    public function getHelp() {
        return $this->help;
    }

    public function getDebug() {
        return $this->debug;
    }

    public function getConfig() {
        return $this->config;
    }

    public function getWorkers() {
        return $this->workers;
    }

    public function getControl() {
        return $this->control;
    }

    public function toArray() {
        return array_filter([
            'help' => $this->help,
            'debug' => $this->debug,
            'config' => $this->config,
            'workers' => $this->workers,
            'control' => $this->control,
        ]);
    }

    public function __toString() {
        $parts[] = $this->help ? "-h" : NULL;
        $parts[] = $this->debug ? "-d" : NULL;
        $parts[] = $this->config ? ('-c ' . escapeshellarg($this->config)) : NULL;
        $parts[] = $this->workers ? ('-w ' . $this->workers) : NULL;
        $parts[] = $this->control ? ('-z ' . escapeshellarg($this->control)) : NULL;

        $parts = array_filter($parts, function($i) { return isset($i); });

        return implode(' ', $parts);
    }
}

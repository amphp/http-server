<?php

namespace Aerys\Start;

class BinOptions {
    private $help;
    private $config;
    private $workers;
    private $port;
    private $ip;
    private $name;
    private $root;
    private $control;
    private $backend;
    private $shortOpts = 'hc:w:p:i:n:r:z:b:';
    private $longOpts = [
        'help',
        'config:',
        'workers:',
        'port:',
        'ip:',
        'name:',
        'root:',
        'control:',
        'backend:'
    ];
    private $shortOptNameMap = [
        'h' => 'help',
        'c' => 'config',
        'w' => 'workers',
        'p' => 'port',
        'i' => 'ip',
        'n' => 'name',
        'r' => 'root',
        'z' => 'control',
        'b' => 'backend'
    ];

    /**
     * Load command line options that may be used to bootstrap a server
     *
     * @param array $options Used if defined, loaded from the CLI otherwise
     * @throws \Aerys\Start\StartException
     * @return \Aerys\Start\BinOptions Returns the current object instance
     */
    public function loadOptions(array $options = NULL) {
        $rawOptions = isset($options) ? $options : $this->getCommandLineOptions();

        $normalizedOptions = [
            'help' => NULL,
            'config' => NULL,
            'workers' => NULL,
            'port' => NULL,
            'ip' => NULL,
            'name' => NULL,
            'root' => NULL,
            'control' => NULL,
            'backend' => NULL
        ];

        foreach ($rawOptions as $key => $value) {
            if (isset($this->shortOptNameMap[$key])) {
                $normalizedOptions[$this->shortOptNameMap[$key]] = $value;
            } else {
                $normalizedOptions[$key] = $value;
            }
        }

        $this->setOptionValues($normalizedOptions);

        if (!($this->help || $this->config || $this->root)) {
            throw new StartException(
                'App config file (-c, --config) or document root directory (-r, --root) required'
            );
        }

        return $this;
    }

    private function getCommandLineOptions() {
        return getopt($this->shortOpts, $this->longOpts);
    }

    private function setOptionValues(array $options) {
        foreach ($options as $key => $value) {
            switch ($key) {
                case 'help':
                    $this->help = isset($value) ? TRUE : NULL;
                    break;
                case 'config':
                    $this->config = $value;
                    break;
                case 'workers':
                    $this->setWorkers($value);
                    break;
                case 'port':
                    $this->port = $value;
                    break;
                case 'ip':
                    $this->ip = $value;
                    break;
                case 'name':
                    $this->name = $value;
                    break;
                case 'root':
                    $this->root = $value;
                    break;
                case 'control':
                    $this->control = $value;
                    break;
                case 'backend':
                    $this->backend = $value;
                    break;
            }
        }
    }

    private function setWorkers($count) {
        $this->workers = filter_var($count, FILTER_VALIDATE_INT, ['options' => [
            'default' => 0,
            'min_range' => 1
        ]]);
    }

    public function getHelp() {
        return $this->help;
    }

    public function getConfig() {
        return $this->config;
    }

    public function getWorkers() {
        return $this->workers;
    }

    public function getPort() {
        return $this->port;
    }

    public function getIp() {
        return $this->ip;
    }

    public function getName() {
        return $this->name;
    }

    public function getRoot() {
        return $this->root;
    }

    public function getControl() {
        return $this->control;
    }

    public function getBackend() {
        return $this->backend;
    }

    public function toArray() {
        return array_filter([
            'help' => $this->help,
            'config' => $this->config,
            'workers' => $this->workers,
            'port' => $this->port,
            'ip' => $this->ip,
            'name' => $this->name,
            'root' => $this->root,
            'control' => $this->control,
            'backend' => $this->backend
        ]);
    }

    public function __toString() {
        $parts = [];

        if ($this->help) {
            $parts[] = '-h';
        }
        if ($this->config) {
            $parts[] = '-c ' . escapeshellarg($this->config);
        }
        if ($this->workers) {
            $parts[] = '-w ' . $this->workers;
        }
        if ($this->port) {
            $parts[] = '-p ' . $this->port;
        }
        if ($this->ip) {
            $parts[] = '-i ' . $this->ip;
        }
        if ($this->name) {
            $parts[] = '-n ' . $this->name;
        }
        if ($this->root) {
            $parts[] = '-r ' . $this->root;
        }
        if ($this->control) {
            $parts[] = '-z ' . escapeshellarg($this->control);
        }
        if ($this->backend) {
            $parts[] = '-b ' . escapeshellarg($this->backend);
        }

        return implode(' ', $parts);
    }
}

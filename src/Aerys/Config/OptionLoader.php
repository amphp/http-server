<?php

namespace Aerys\Config;

use Aerys\Handlers\DocRoot\DocRootLauncher;

class OptionLoader {
    
    private $config;
    private $shortOpts = 'c:b:n:d:h';
    private $longOpts = [
        'config:',
        'bind:',
        'name:',
        'docroot:',
        'help'
    ];
    private $optNameMap = [
        'c' => 'config',
        'b' => 'bind',
        'n' => 'name',
        'd' => 'docroot',
        'h' => 'help'
    ];
    private $options = [
        'config' => NULL,
        'bind' => NULL,
        'name' => NULL,
        'docroot' => NULL
    ];

    function loadOptions() {
        $options = getopt($this->shortOpts, $this->longOpts);

        if (isset($options['h']) || isset($options['help'])) {
            $isSuccess = FALSE;
        } else {
            $isSuccess = $this->doOptions($options);
        }

        return $isSuccess ?: $this->displayHelp();
    }
    
    function getConfig() {
        return $this->config;
    }

    private function displayHelp() {
        echo <<<EOT

php aerys.php --config="/path/to/server/config.php"
php aerys.php --bind="*:80" --name="mysite.com" --root="/path/to/document/root"

-c, --config     Use a config file to bootstrap the server
-b, --bind       The server's address and port (e.g. 127.0.0.1:80 or *:80)
-n, --name       Optional host (domain) name
-d, --docroot    The filesystem directory from which to serve static files
-h, --help       Display help screen


EOT;
        return FALSE;
    }

    private function doOptions(array $options) {
        try {
            $this->normalizeOptionKeys($options);
            
            if ($this->options['config']) {
                $this->generateConfigFromFile();
            } else {
                $this->generateDocRootConfig();
            }
            
            return $canContinue = TRUE;
            
        } catch (\RuntimeException $e) {
            echo "\n", $e->getMessage(), "\n";
        }
    }

    private function normalizeOptionKeys(array $options) {
        foreach ($options as $key => $value) {
            if (isset($this->optNameMap[$key])) {
                $this->options[$this->optNameMap[$key]] = $value;
            } else {
                $this->options[$key] = $value;
            }
        }
    }
    
    private function generateConfigFromFile() {
        $configFile = realpath($this->options['config']);
        
        if (!(is_file($configFile) && is_readable($configFile))) {
            throw new \RuntimeException(
                "Config file could not be read: {$configFile}"
            );
        }
        
        $cmd = PHP_BINARY . ' -l ' . $configFile . '  && exit';
        exec($cmd, $outputLines, $exitCode);

        if ($exitCode) {
            throw new \RuntimeException(
                "Config file failed lint test" . PHP_EOL . implode(PHP_EOL, $outputLines)
            );
        }
        
        $nonEmptyOpts = array_filter($this->options);
        
        unset($nonEmptyOpts['config']);
        
        if ($nonEmptyOpts) {
            throw new \RuntimeException(
                "Config incompatible with other directives: " . implode(', ', array_keys($nonEmptyOpts))
            );
        }
        
        $configFile = $this->options['config'];
        
        if (!@include $configFile) {
            throw new \RuntimeException(
                "Config file inclusion failure: {$configFile}"
            );
        }
        
        if (!(isset($config) && is_array($config))) {
            throw new \RuntimeException(
                'Config file must specify a $config array'
            );
        }
        
        $this->config = $config;
    }

    private function generateDocRootConfig() {
        if (empty($this->options['bind'])) {
            throw new \RuntimeException(
                'Bind address required (e.g. --bind=*:80 or -b"127.0.0.1:80")'
            );
        }
        
        $bind = $this->options['bind'];
        
        if ($bind[0] === '*') {
            $bind = str_replace('*', '0.0.0.0', $bind);
        } elseif (!filter_var($bind, FILTER_VALIDATE_IP)) {
            throw new \RuntimeException(
                "Invalid bind address: {$bind}"
            );
        }
        
        if (empty($this->options['docroot'])) {
            throw new ConfigException(
                'Document root directive required (e.g. -d="/path/to/files", --docroot)'
            );
        }
        
        $docroot = realpath($this->options['docroot']);
        
        if (!(is_dir($docroot) && is_readable($docroot))) {
            throw new ConfigException(
                'Document root directive must specify a readable directory path'
            );
        }
        
        $configArr = [
            'listenOn' => $this->options['bind'],
            'application' => new DocRootLauncher([
                'docRoot' => $docroot
            ])
        ];
        
        if ($this->config['name']) {
            $configArr['name'] = $name;
        }
        
        $this->config = [$configArr];
    }
    
}

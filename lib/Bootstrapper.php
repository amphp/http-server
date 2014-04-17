<?php

namespace Aerys;

use Alert\Reactor,
    Alert\ReactorFactory,
    Auryn\Injector,
    Auryn\Provider;

class Bootstrapper {
    const OPT_VAR_PREFIX = '__';
    private static $ILLEGAL_CONFIG_VAR = 'Illegal config variable; "%s" is a reserved name';

    /**
     * Bootstrap a server and its host definitions from command line arguments
     *
     * @param string $config The application config file path
     * @param array $options
     * @throws \Aerys\Framework\StartException
     * @return array Returns three-element array of the form [$reactor, $server, $hosts]
     */
    public function boot($config, array $opts = []) {
        $bindOpt = isset($opts['bind']) ? (bool) $opts['bind'] : TRUE;
        $socksOpt = isset($opts['socks']) ? (array) $opts['socks'] : [];
        $debugOpt = isset($opts['debug']) ? (bool) $opts['debug'] : FALSE;

        list($reactor, $injector, $apps, $serverOpts) = $this->parseAppConfig($config);

        $injector->alias('Alert\Reactor', get_class($reactor));
        $injector->share($reactor);
        $injector->share('Aerys\Server');
        $injector->define('Aerys\ResponderBuilder', [':injector' => $injector]);
        $server = $injector->make('Aerys\Server', [':debug' => $debugOpt]);

        // Automatically register any ServerObserver implementations with the server
        $injector->prepare('Aerys\ServerObserver', function($observer) use ($server) {
            $server->addObserver($observer);
        });

        $hostBuilder = $injector->make('Aerys\HostBuilder');
        $hosts = $injector->make('Aerys\HostCollection');

        foreach ($apps as $app) {
            $host = $hostBuilder->buildHost($app);
            $hosts->addHost($host);
        }

        $allowedOptions = array_map('strtolower', array_keys($server->getAllOptions()));
        foreach ($serverOpts as $key => $value) {
            if (in_array(strtolower($key), $allowedOptions)) {
                $server->setOption($key, $value);
            }
        }

        if ($bindOpt) {
            $server->start($hosts, $socksOpt);
        }

        return [$reactor, $server, $hosts];
    }

    private function parseAppConfig($__config) {
        if (!include($__config)) {
            throw new StartException(
                sprintf("Failed including config file: %s", $__config)
            );
        }

        if (!(isset($__config) && $__config === func_get_args()[0])) {
            throw new StartException(
                sprintf(self::$ILLEGAL_CONFIG_VAR, "__config")
            );
        }

        if (isset($__vars)) {
            throw new StartException(
                sprintf(self::$ILLEGAL_CONFIG_VAR, "__vars")
            );
        }

        $__vars = get_defined_vars();

        foreach (['__apps', '__reactors', '__injectors', '__options'] as $reserved) {
            if (isset($__vars[$reserved])) {
                throw new StartException(
                    sprintf(self::$ILLEGAL_CONFIG_VAR, $reserved)
                );
            }
        }

        $__apps = $__reactors = $__injectors = $__options = [];

        foreach ($__vars as $key => $value) {
            if ($value instanceof App) {
                $__apps[] = $value;
            } elseif ($value instanceof Injector) {
                $__injectors[] = $value;
            } elseif ($value instanceof Reactor) {
                $__reactors[] = $value;
            } elseif (substr($key, 0, 2) === self::OPT_VAR_PREFIX) {
                $key = substr($key, 2);
                $__options[$key] = $value;
            }
        }

        if (empty($__apps)) {
            throw new StartException(
                "No app configuration instances found in config file"
            );
        }

        $reactor = $__reactors ? end($__reactors) : (new ReactorFactory)->select();
        $injector = $__injectors ? end($__injectors) : new Provider;

        return [$reactor, $injector, $__apps, $__options];
    }
}

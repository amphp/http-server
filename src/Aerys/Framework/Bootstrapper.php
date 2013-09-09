<?php

namespace Aerys\Framework;

use Alert\Reactor,
    Aerys\Server;

class Bootstrapper {

    private $hostBuilder;

    function __construct(HostBuilder $hb = NULL) {
        $this->hostBuilder = $hb ?: new HostBuilder;
    }

    /**
     * Bootstrap an aerys server using command line executable options
     *
     * @param \Alert\Reactor The event reactor underlying everything
     * @param \Aerys\Server The server instance we're bootstrapping
     * @param \Aerys\Framework\BinOptions $binOptions
     * @throws \Aerys\Framework\ConfigException
     * @return \Aerys\Server Returns the bootstrapped server instance
     */
    function boot(Reactor $reactor, Server $server, BinOptions $binOptions) {
        $this->reactor = $reactor;
        $this->server = $server;

        if ($binOptions->getConfig()) {
            $this->configureFromFile($binOptions);
        } else {
            $this->configureDocRoot($binOptions);
        }

        return $this->server;
    }

    private function configureFromFile(BinOptions $binOptions) {
        $configFile = $binOptions->getConfig();

        if (!include($configFile)) {
            throw new ConfigException(
                "Config file inclusion failed: {$configFile}"
            );
        }

        $vars = get_defined_vars();

        $apps = [];
        $options = [];
        $injectors = [];

        foreach ($vars as $key => $value) {
            if ($value instanceof App) {
                $apps[] = $value;
            } elseif ($value instanceof ServerOptions) {
                $options[] = $value;
            } elseif ($value instanceof Injector) {
                $injectors[] = $value;
            }
        }

        if (!$apps) {
            throw new ConfigException(
                sprintf('No Aerys\Framework\App configuration objects found in config file: %s', $configFile)
            );
        } elseif (count($options) > 1) {
            throw new ConfigException(
                sprintf('Only one Aerys\Framework\ServerOptions instance allowed in config file: %s', $configFile)
            );
        } elseif (count($injectors) > 1) {
            throw new ConfigException(
                sprintf('Only one Auryn\Injector instance allowed in config file: %s', $configFile)
            );
        }

        $options = $options ? current($options) : NULL;
        $injector = $injectors ? current($injectors) : NULL;

        $appSettings = new AppSettings($apps, $options, $injector);

        $this->bootFromAppSettings($appSettings);
    }

    private function configureDocRoot(BinOptions $binOptions) {
        $docroot = realpath($binOptions->getRoot());

        if (!($docroot && is_dir($docroot) && is_readable($docroot))) {
            throw new ConfigException(
                sprintf('Invalid docroot path: %s', $options['root'])
            );
        }

        $app = (new App)->setDocumentRoot($docroot);

        if ($port = $binOptions->getPort()) {
            $app->setPort($port);
        }
        if ($ip = $binOptions->getIp()) {
            $app->setAddress($ip);
        }
        if ($name = $binOptions->getName()) {
            $app->setName($name);
        }

        $appSettings = new AppSettings([$app]);

        $this->bootFromAppSettings($appSettings);
    }

    private function bootFromAppSettings(AppSettings $appSettings) {
        $injector = $appSettings->getInjector();

        $injector->alias('Alert\Reactor', get_class($this->reactor));
        $injector->share($this->reactor);
        $injector->share($this->server);

        $this->hostBuilder->setInjector($injector);

        foreach ($appSettings->getApps() as $app) {
            $host = $this->hostBuilder->buildHost($app);
            $this->server->addHost($host);
        }

        $serverOptions = $appSettings->getOptions();
        $opts = $serverOptions->getAllOptions();
        $this->server->setAllOptions($opts);
    }

}

<?php

declare(strict_types=1);

namespace App;

use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\ContainerLoader;
use SixtyEightPublishers\Environment\Bootstrap\EnvBootstrap;
use SixtyEightPublishers\Environment\Debug\EnvDetector;
use Tracy\Bridges\Nette\TracyExtension;
use function assert;
use function method_exists;

final class Bootstrap
{
    private function __construct() {}

    public static function boot(): Container
    {
        $env = EnvBootstrap::boot([new EnvDetector()]);
        $debugMode = (bool) $env[EnvBootstrap::APP_DEBUG];

        $loader = new ContainerLoader(__DIR__ . '/../var/cache/nette-container', $debugMode);

        $class = $loader->load(function (Compiler $compiler) use ($debugMode) {
            $compiler->addExtension('tracy', new TracyExtension($debugMode, true));
            $compiler->loadConfig(__DIR__ . '/../config/config.neon');
        });

        $container = new $class;
        assert($container instanceof Container && method_exists($container, 'initialize'));

        $container->initialize();

        return $container;
    }
}

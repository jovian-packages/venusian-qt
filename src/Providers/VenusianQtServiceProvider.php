<?php

namespace Jovian\Toolkits\Qt\Providers;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use Surface\Toolkits\Toolkits;
use Jovian\Toolkits\Qt\QtBridge;
use Voyager\Contracts\Core\FrameworkCore;
use Voyager\NutsAndBolts\ServiceProvider;

class VenusianQtServiceProvider extends ServiceProvider
{
    /**
     * The Qt driver is the bridge's: resolving it by its contract asks the toolkit manager,
     * so there is one driver, one session and one set of windows.
     *
     * @return void
     * @throws ReflectionException
     */
    public function register(): void
    {
        $this->app->registerSingleton(QtBridge::class, fn (FrameworkCore $app) => Toolkits::bridge('qt'));
        $this->app->alias('qt-bridge', QtBridge::class);
    }

    /**
     * The toolkit's driver, registered on the bridge by this package: Surface names no toolkit.
     *
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function boot(): void
    {
        $toolkits = $this->app->get('toolkit-bridge-manager');
        $toolkits->extend('qt', fn ($app): QtBridge => new QtBridge($app));
    }
}

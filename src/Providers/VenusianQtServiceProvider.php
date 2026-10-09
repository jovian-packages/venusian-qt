<?php

namespace Jovian\Toolkits\Qt\Providers;

use ReflectionException;
use Voyager\Contracts\Core\FrameworkCore;
use Voyager\NutsAndBolts\ServiceProvider;
use Jovian\Toolkits\Qt\Bridge\QtBridgeDriver as Driver;
use Jovian\Toolkits\Qt\Contracts\Bridge\QtBridgeDriver as DriverContract;

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
        $this->app->registerSingleton(DriverContract::class, fn (FrameworkCore $app) => $app->get('toolkit-bridge')->driver('qt'));
        $this->app->alias('qt-bridge', DriverContract::class);
    }

    /** The toolkit's driver, registered on the bridge by this package: Surface names no toolkit. */
    public function boot(): void
    {
        $this->app->get('toolkit-bridge')->extend('qt', fn ($app): Driver => new Driver($app));
    }
}

<?php

namespace Jovian\Toolkits\Qt\Providers;

use Jovian\Toolkits\Qt\Contracts\Bridge\QtBridgeDriver;
use ReflectionException;
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
        $this->app->registerSingleton(QtBridgeDriver::class, fn (FrameworkCore $app) => $app->get('toolkit-bridge')->driver('qt'));
    }

    public function boot(): void
    {

    }
}

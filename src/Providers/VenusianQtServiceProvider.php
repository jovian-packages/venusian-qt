<?php

namespace Jovian\Toolkits\Qt\Providers;

use Jovian\Toolkits\Qt\QtAdapterDriver;
use Surface\Toolkits\ToolkitAdapterServiceProvider;

class VenusianQtServiceProvider extends ToolkitAdapterServiceProvider
{
    protected string $toolkit = 'qt';

    protected string $driver = QtAdapterDriver::class;

    protected string $config = __DIR__.'/../../config/qt.php';
}

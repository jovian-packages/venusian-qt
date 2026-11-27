<?php

namespace Jovian\Toolkits\Qt\Video;

use Jovian\Toolkits\Qt\Video\Multimedia\MultimediaVideoDriver;
use Jovian\Toolkits\Qt\Video\WebEngine\WebEngineVideoDriver;
use Voyager\NutsAndBolts\Manager;

/**
 * The players this package's videos are made with: one driver per name, made the first time
 * it is asked for. The default is qt.video.driver. 'qt' (Qt Multimedia) and 'webview' (Qt
 * WebEngine) are built in; another package or the app adds a driver with extend().
 */
class QtVideoManager extends Manager
{
    public function getDefaultDriver(): ?string
    {
        return $this->config->get('qt.video.driver');
    }

    public function driver(?string $driver = null): QtVideoDriver
    {
        return parent::driver($driver);
    }

    public function createQtDriver(): MultimediaVideoDriver
    {
        return new MultimediaVideoDriver();
    }

    public function createWebviewDriver(): WebEngineVideoDriver
    {
        return new WebEngineVideoDriver();
    }
}

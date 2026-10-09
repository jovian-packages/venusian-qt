<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Bridge\QtBridgeDriver;
use Surface\Contracts\Bridge\BridgeException;
use Voyager\Config\Repository;
use Voyager\Vessel\ControlPanel;

/*
 * The desktop identity is the app's: config/app.php app.id, which a packaged
 * build's .desktop file is named after. No fallback id: two apps sharing one
 * would share one dock entry.
 */
it('takes the desktop identity from app.id', function (): void {
    session();

    expect(QGuiApplication::desktopFileName())->toBe('org.venusian.QtDriverTests');
});

it('refuses to connect without app.id, saying where it goes', function (): void {
    $container = new ControlPanel();
    $container->registerInstance('config', new Repository(['app' => ['name' => 'No Id']]));

    (new QtBridgeDriver($container))->connect();
})->throws(BridgeException::class, "config/app.php has no app.id; add 'id' => env('APP_ID', 'com.venusian.app') under name.");

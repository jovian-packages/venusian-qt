<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Bridge\QtBridgeDriver;
use Jovian\Toolkits\Qt\Bridge\QtSession;
use Surface\Bridge\ToolkitManager;
use Surface\Windows\ToolkitWindowManager;
use Voyager\Config\Repository;
use Voyager\Vessel\ControlPanel;

if (! extension_loaded('qt')) {
    throw new RuntimeException('venusian-qt tests need ext-qt loaded.');
}

const TEST_MENUS = [
    'main' => [
        ['label' => 'App', 'items' => [
            ['role' => 'about', 'label' => 'About'],
            ['separator' => true],
            ['role' => 'quit', 'label' => 'Quit', 'hotkey' => 'q'],
        ]],
        ['label' => 'View', 'items' => [
            ['id' => 'grid', 'label' => 'Show Grid', 'toggle' => true, 'on' => false],
            ['id' => 'view.refresh', 'label' => 'Refresh', 'hotkey' => 'R'],
        ]],
    ],
    'tools' => [
        ['label' => 'Tools', 'items' => [
            ['id' => 'tools.measure', 'label' => 'Measure'],
        ]],
    ],
];

/**
 * The one driver for the process: one QApplication per process, so every test shares
 * the driver, its session and its windows, through a container like the framework's.
 */
function driver(): QtBridgeDriver
{
    static $driver = null;

    if (is_null($driver)) {
        $container = new ControlPanel();
        $container->registerInstance('config', new Repository([
            'bridge' => ['qt' => ['application_name' => 'QtDriverTests', 'desktop_file_name' => 'org.venusian.QtDriverTests']],
            'windows' => [
                'about' => ['name' => 'venusian-qt tests', 'version' => '0.10.0', 'copyright' => null],
                'default_menu' => 'main',
                'menus' => TEST_MENUS,
            ],
        ]));
        $container->registerInstance('toolkit-bridge', $toolkits = new ToolkitManager($container));
        $container->registerInstance('toolkit-windows', new ToolkitWindowManager($toolkits, TEST_MENUS, 'main'));
        $driver = new QtBridgeDriver($container);
    }

    return $driver;
}

function session(): QtSession
{
    return driver()->connect();
}

/** Mail the session is holding for a loop, taken out. */
function takeMail(QtSession $session): array
{
    return (function (): array {
        [$mail, $this->outbox] = [$this->outbox, []];

        return $mail;
    })->call($session);
}

/** Pump Qt for $seconds. */
function pumpFor(float $seconds): void
{
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        session()->pump(10_000_000);
    }
}

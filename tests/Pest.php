<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Bridge\QtBridgeDriver;
use Jovian\Toolkits\Qt\Bridge\QtSession;
use Surface\Bridge\ToolkitManager;
use Surface\Bridge\ToolkitPump;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\SurfaceBorrower;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Framebuffers\Native\NativeDirtyFramebuffer;
use Surface\Windows\ToolkitWindowManager;
use Voyager\Config\Repository;
use Voyager\Vessel\ControlPanel;

if (! extension_loaded('qt')) {
    throw new RuntimeException('venusian-qt tests need ext-qt loaded.');
}

// Qt loads the Vulkan loader by name: on macOS Homebrew's lies outside dyld's search path, so name it.
if (PHP_OS_FAMILY === 'Darwin' && extension_loaded('vulkan') && getenv('QT_VULKAN_LIB') === false) {
    putenv('QT_VULKAN_LIB='.trim((string) shell_exec('pkg-config --variable=libdir vulkan')).'/libvulkan.1.dylib');
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
            'app' => ['name' => 'venusian-qt tests', 'id' => 'org.venusian.QtDriverTests'],
            'bridge' => ['qt' => ['application_name' => 'QtDriverTests']],
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

/** Keys of the latest mail the session holds until the next flush. */
function pendingLatest(QtSession $session): array
{
    return (function (): array {
        return array_keys($this->latest);
    })->call($session);
}

/** Pump Qt for $seconds through the loop's own sleeper, so latest mail is flushed after each pump as the loop does. */
function pumpFor(float $seconds): void
{
    $pump = new ToolkitPump(session());
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        $pump->sleep(10_000_000);
    }
}

/** A borrower that keeps what it was asked to present into; its framebuffer is a plain dirty one. */
final class QtLayerBorrower implements SurfaceBorrower
{
    /** @var list<LentSurface> */
    public array $presented = [];

    public Framebuffer $frame;

    public function __construct()
    {
        $this->frame = new NativeDirtyFramebuffer(FormatSpec::rgba8(), 8, 8);
    }

    public function framebuffer(): Framebuffer
    {
        return $this->frame;
    }

    public function lendingHandles(): array
    {
        return [];
    }

    public function presentInto(LentSurface $surface): bool
    {
        $this->presented[] = $surface;

        return true;
    }
}

/** @return list<SurfaceKind> The Vulkan surface, first in every canvas's list, where Qt makes one here. */
function qtVulkanKinds(): array
{
    return Jovian\Toolkits\Qt\Primitives\QtCanvas::lendsVulkan() ? [Surface\Contracts\Drawing\SurfaceKind::VULKAN_SURFACE] : [];
}

/** What the canvas lends here: a Vulkan surface first where Qt makes one, then the Metal and SDL kinds on macOS where they are offered. */
function qtLends(): string
{
    $kinds = qtVulkanKinds() === [] ? [] : ['vulkan-surface'];
    if (PHP_OS_FAMILY === 'Darwin' && class_exists(NSView::class) && class_exists(CAMetalLayer::class)) {
        $kinds = [...$kinds, 'metal-layer', ...(function_exists('SDL_CreateWindowWithProperties') ? ['sdl-window'] : [])];
    }
    // GL over CGL on macOS; elsewhere only where Qt's GL is EGL (Wayland, eglfs, or xcb with xcb_egl), as the canvas offers it.
    $platform = QGuiApplication::platformName();
    $egl = str_starts_with($platform, 'wayland') || $platform === 'eglfs' || ($platform === 'xcb' && getenv('QT_XCB_GL_INTEGRATION') === 'xcb_egl');
    if (extension_loaded('opengl') && (PHP_OS_FAMILY === 'Darwin' || $egl)) {
        $kinds[] = 'gl-context';
    }

    return implode(', ', $kinds);
}

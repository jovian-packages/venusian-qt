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
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;
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
            ['id' => 'view.refresh', 'label' => 'Refresh', 'keys' => 'Primary+Shift+F5'],
            ['id' => 'view.next', 'label' => 'Next Difference', 'keys' => 'Alt+Down'],
            ['id' => 'view.tab', 'label' => 'Next Tab', 'keys' => 'Primary+]'],
            ['id' => 'view.erase', 'label' => 'Erase', 'keys' => 'Primary+BACKSPACE'],
            ['id' => 'view.locked', 'label' => 'Locked', 'enabled' => false, 'items' => [
                ['id' => 'view.locked.item', 'label' => 'Item'],
            ]],
        ]],
    ],
    'choices' => [
        ['label' => 'App', 'items' => [['role' => 'quit', 'label' => 'Quit', 'hotkey' => 'q']]],
        ['id' => 'compare', 'label' => 'Compare', 'items' => [
            ['id' => 'case.none', 'label' => 'None', 'radio' => 'ignore-case', 'on' => true],
            ['id' => 'case.unimportant', 'label' => 'Unimportant', 'radio' => 'ignore-case'],
            ['id' => 'case.all', 'label' => 'All', 'radio' => 'ignore-case'],
            ['separator' => true],
            ['id' => 'compare.wrap', 'label' => 'Wrap', 'toggle' => true],
            ['id' => 'compare.more', 'label' => 'More', 'items' => [['id' => 'compare.more.deep', 'label' => 'Deep']]],
        ]],
    ],
    'tools' => [
        ['label' => 'Tools', 'items' => [
            ['id' => 'tools.measure', 'label' => 'Measure'],
        ]],
    ],
    'editing' => [
        ['label' => 'App', 'items' => [
            ['role' => 'quit', 'label' => 'Quit', 'hotkey' => 'q'],
        ]],
        ['label' => 'Edit', 'items' => [
            ['role' => 'undo', 'label' => 'Undo'],
            ['role' => 'redo', 'label' => 'Redo'],
            ['separator' => true],
            ['role' => 'cut', 'label' => 'Cut'],
            ['role' => 'copy', 'label' => 'Copy'],
            ['role' => 'paste', 'label' => 'Paste'],
            ['role' => 'select-all', 'label' => 'Select All'],
            ['separator' => true],
            ['id' => 'edit.find', 'label' => 'Find'],
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

/** The primitives' mail taken out, the window and lifecycle mail a present or a pump brings dropped. */
function viewMail(QtSession $session): array
{
    return array_values(array_filter(takeMail($session), fn (object $mail): bool => $mail instanceof PrimitiveMail));
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

/** @return array{int, int} a Qt size as [width, height] */
function wh(QSize $size): array
{
    return [$size->width(), $size->height()];
}

/** @return array{int, int} a Qt point as [x, y] */
function xy(QPoint $point): array
{
    return [$point->x(), $point->y()];
}

/**
 * Pump until $area's layout has settled: its widget is visible and its scroll bars read the same
 * value, maximum and page step on two pumps in a row; say whether that happened within $seconds.
 */
function settled(Jovian\Toolkits\Qt\Primitives\QtTextArea $area, float $seconds = 3.0): bool
{
    $pump = new ToolkitPump(session());
    $until = microtime(true) + $seconds;
    $previous = null;
    while (microtime(true) < $until) {
        $pump->sleep(10_000_000);
        [$horizontal, $vertical] = [$area->native()->horizontalScrollBar(), $area->native()->verticalScrollBar()];
        $now = [
            $horizontal->value(), $horizontal->maximum(), $horizontal->pageStep(),
            $vertical->value(), $vertical->maximum(), $vertical->pageStep(),
        ];
        if ($area->native()->isVisible() && $now === $previous) {
            return true;
        }
        $previous = $now;
    }

    return false;
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

/** Skip unless $device's engine package is installed: the engines are suggested, not required. */
function requireEngine(string $device): void
{
    if (! class_exists($device)) {
        test()->markTestSkipped("needs {$device}'s engine package");
    }
}

/**
 * A shown window's widget, and the global point of ($x, $y) in it. The clicks hit-test the screen,
 * so the window must be active, on top; macOS may decline that while another app is in front, and
 * there the test is skipped with that reason.
 */
function canvasShownAt(Jovian\Toolkits\Qt\Windows\QtWindow $window, QWidget $widget, float $x, float $y): array
{
    $window->present();
    for ($waited = 0; (! $widget->isVisible() || ! $window->native()->isActiveWindow()) && $waited < 60; $waited++) {
        pumpFor(0.05);
    }
    if (! $window->native()->isActiveWindow()) {
        if (PHP_OS_FAMILY === 'Darwin') {
            test()->markTestSkipped('macOS declined to activate the app: another app holds focus (cooperative activation).');
        }
        throw new RuntimeException("Window '{$window->name()}' did not become active.");
    }
    pumpFor(0.2);
    $origin = $widget->mapFromGlobal(new QPointF(0.0, 0.0));

    return [$x - $origin->x(), $y - $origin->y()];
}

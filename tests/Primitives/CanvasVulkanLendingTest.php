<?php

declare(strict_types=1);

use Jovian\Engines\Vulkan\VulkanDevice;
use Jovian\Toolkits\Qt\Primitives\QtCanvas;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Drawing\SurfaceBorrower;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Windows\WindowException;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\Framebuffers\Native\NativeDirtyFramebuffer;
use Surface\NutsAndBolts\Color;

/*
 * The canvas lends a Vulkan surface: a Vulkan QWindow whose QVulkanInstance is
 * the borrower's VkInstance. These tests draw real frames through
 * venusian-vulkan into a window on screen.
 */

/** A borrower with a real instance to make the surface against; its framebuffer is a plain dirty one. */
final class QtVulkanBorrower implements SurfaceBorrower
{
    public VulkanDevice $device;

    public Framebuffer $frame;

    public function __construct()
    {
        $this->device = new VulkanDevice;
        $this->frame = new NativeDirtyFramebuffer(FormatSpec::rgba8(), 8, 8);
    }

    public function framebuffer(): Framebuffer
    {
        return $this->frame;
    }

    public function lendingHandles(): array
    {
        return $this->device->handles();
    }

    public function presentInto(LentSurface $surface): bool
    {
        return true;
    }
}

beforeEach(function (): void {
    session();
    if (! extension_loaded('vulkan') || ! QtCanvas::lendsVulkan()) {
        $this->markTestSkipped('needs ext-vulkan and a Qt that makes Vulkan instances');
    }
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

afterEach(function (): void {
    if (! extension_loaded('vulkan')) {
        return;
    }
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** @return array{\Jovian\Toolkits\Qt\Windows\QtWindow, QtCanvas} a shown window with a filling canvas */
function qtVulkanCanvas(int $width = 300, int $height = 200): array
{
    $window = driver()->open('main', $width, $height);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    $until = microtime(true) + 3.0;
    while ($canvas->size()[1] === 0 && microtime(true) < $until) {
        pumpFor(0.02);
    }

    return [$window, $canvas];
}

it('lends a Vulkan surface made against the borrower\'s instance', function (): void {
    [$window, $canvas] = qtVulkanCanvas();
    $borrower = new QtVulkanBorrower;

    expect($canvas->surfaces()[0])->toBe(SurfaceKind::VULKAN_SURFACE);

    $surface = $canvas->lend(SurfaceKind::VULKAN_SURFACE, $borrower);
    // The device's queue must be able to present into it: adopt() checks with vkGetPhysicalDeviceSurfaceSupportKHR.
    $borrower->device->adopt($surface);

    expect($surface->handle('surface'))->toBeGreaterThan(0)
        ->and($canvas->vulkanWindow())->toBeInstanceOf(QWindow::class)
        ->and($canvas->vulkanWindow()->surfaceType())->toBe(QSurface\SurfaceType::VULKAN_SURFACE)
        ->and($canvas->vulkanWindow()->vulkanInstance()?->vkInstance())->toBe($borrower->device->instance()->pointer())
        ->and($surface->size())->toBe($canvas->pixelSize())
        ->and($canvas->lent())->toBe($surface);
    $canvas->reclaim();
    $borrower->device->release();
});

it('refuses to lend before the window is shown', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();

    $canvas->lend(SurfaceKind::VULKAN_SURFACE, new QtVulkanBorrower);
})->throws(WindowException::class, 'has no Vulkan surface yet: present the window first');

it('lends nothing a device it cannot host, naming what it can', function (): void {
    [$window, $canvas] = qtVulkanCanvas();

    expect(fn () => $canvas->lend(SurfaceKind::DMABUF, new QtVulkanBorrower))
        ->toThrow(WindowException::class, 'lends no dmabuf surface (it lends: '.qtLends().').')
        ->and(qtLends())->toStartWith('vulkan-surface');
});

it('shows vulkan frames in the window with no pixel through PHP', function (): void {
    [$window, $canvas] = qtVulkanCanvas(320, 240);

    $engine = new GpuRenderingEngine(new VulkanDevice, ...$canvas->pixelSize(), output: $canvas);
    for ($i = 0; $i < 60; $i++) {
        $engine->frame(function (RenderingEngine $g) use ($i, $canvas): void {
            [$width, $height] = $canvas->pixelSize();
            $g->clear(Color::rgb(16, 24, 32));
            $g->fillEllipse($width * (0.2 + 0.6 * $i / 59), $height / 2, $height / 6, $height / 6, Color::rgb(255, 128, 0));
        });
        $canvas->present();
        pumpFor(1 / 60);
    }

    expect($canvas->lent()?->kind)->toBe(SurfaceKind::VULKAN_SURFACE)
        ->and($canvas->lent()?->size())->toBe($canvas->pixelSize())
        ->and($engine->device()->swapchainExtent())->toBe($canvas->pixelSize())
        ->and($canvas->label()->pixmap()->isNull())->toBeTrue();
    $engine->release();
});

it('re-targets the engine when the window is resized', function (): void {
    [$window, $canvas] = qtVulkanCanvas();
    $engine = new GpuRenderingEngine(new VulkanDevice, ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $canvas->present();
    $before = $engine->framebuffer();

    $window->native()->resize(400, 300);
    $until = microtime(true) + 2.0;
    while ($canvas->pixelSize()[0] === $before->viewportWidth() && microtime(true) < $until) {
        pumpFor(0.02);
    }
    // Each frame re-targets to the size the canvas has then; a window that settles late is caught by the next.
    $until = microtime(true) + 2.0;
    do {
        $drawnAt = $canvas->pixelSize();
        $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
        $canvas->present();
        pumpFor(0.05);
    } while ([$engine->width(), $engine->height()] !== $drawnAt && microtime(true) < $until);
    $drawnAt = $canvas->pixelSize();
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));

    expect($engine->framebuffer())->not->toBe($before)
        ->and([$engine->width(), $engine->height()])->toBe($drawnAt)
        ->and($canvas->lent()?->size())->toBe($canvas->pixelSize());
    $engine->release();
});

it('shows its framebuffer again after a reclaim', function (): void {
    [$window, $canvas] = qtVulkanCanvas();
    $own = $canvas->framebuffer('dirty');
    $own->fill(0xFF6600FF);
    $canvas->present();
    $engine = new GpuRenderingEngine(new VulkanDevice, ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 255)));
    $canvas->present();
    pumpFor(0.1);
    $surface = $canvas->lent();

    $engine->release();
    pumpFor(0.1);
    $own->setSegment(10, 10, 20, 20, 0x000000FF);
    $canvas->present();

    expect($surface?->released())->toBeTrue()
        ->and($canvas->vulkanWindow())->toBeNull()
        ->and($canvas->label()->isVisible())->toBeTrue()
        ->and($canvas->label()->pixmap()->isNull())->toBeFalse()
        ->and($canvas->surfaces()[0])->toBe(SurfaceKind::VULKAN_SURFACE);
});

it('reclaims before it is removed', function (): void {
    [$window, $canvas] = qtVulkanCanvas();
    $engine = new GpuRenderingEngine(new VulkanDevice, ...$canvas->pixelSize(), output: $canvas);
    $surface = $canvas->lent();

    $canvas->remove();

    expect($surface?->released())->toBeTrue()
        ->and($canvas->isRemoved())->toBeTrue();
    $engine->release();
});

it('lends and reclaims many times over', function (): void {
    [$window, $canvas] = qtVulkanCanvas();

    for ($i = 0; $i < 20; $i++) {
        $engine = new GpuRenderingEngine(new VulkanDevice, ...$canvas->pixelSize(), output: $canvas);
        $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, $i * 10)));
        $canvas->present();
        pumpFor(0.01);
        $engine->release();
    }

    expect($canvas->lent())->toBeNull()
        ->and($canvas->vulkanWindow())->toBeNull();
});

it('takes its surface back when the device is let go first, before the device\'s instance goes', function (): void {
    [$window, $canvas] = qtVulkanCanvas();
    $engine = new GpuRenderingEngine(new VulkanDevice, ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 255)));
    $canvas->present();
    pumpFor(0.05);
    $surface = $canvas->lent();
    $kept = $canvas->vulkanWindow()->vulkanInstance();

    $engine->device()->release();

    expect($surface?->released())->toBeTrue()
        ->and($canvas->lent())->toBeNull()
        ->and($canvas->vulkanWindow())->toBeNull()
        ->and($kept?->isValid())->toBeFalse()
        ->and($canvas->label()->isVisible())->toBeTrue();
});

it('offers no Vulkan surface when Qt would load another Vulkan loader than ext-vulkan\'s', function (): void {
    $named = getenv('QT_VULKAN_LIB');
    putenv('QT_VULKAN_LIB='.sys_get_temp_dir());
    try {
        $refused = QtCanvas::lendsVulkan();
    } finally {
        putenv($named === false ? 'QT_VULKAN_LIB' : "QT_VULKAN_LIB={$named}");
    }

    expect($refused)->toBeFalse()
        ->and(QtCanvas::lendsVulkan())->toBeTrue();
});

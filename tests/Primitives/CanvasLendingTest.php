<?php

declare(strict_types=1);

use Jovian\Engines\Metal\MetalDevice;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Windows\WindowException;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\NutsAndBolts\Color;

/*
 * On macOS the canvas lends the layer Qt makes for a Metal QWindow, contained
 * over its label, to a GPU engine. These tests draw real frames through
 * venusian-metal into a window on screen.
 */

beforeEach(function (): void {
    if (PHP_OS_FAMILY !== 'Darwin' || ! class_exists(NSView::class) || ! class_exists(CAMetalLayer::class)) {
        $this->markTestSkipped('Metal lending is macOS with ext-appkit and ext-metal');
    }
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

afterEach(function (): void {
    if (PHP_OS_FAMILY !== 'Darwin' || ! class_exists(NSView::class) || ! class_exists(CAMetalLayer::class)) {
        return;
    }
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

it('lends a Metal layer when ext-appkit and ext-metal are loaded', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);
    $children = count($canvas->native()->children());

    // The Metal layer first; ext-sdl3 adds the SDL window after it.
    expect($canvas->surfaces()[0])->toBe(SurfaceKind::METAL_LAYER);

    $surface = $canvas->lend(SurfaceKind::METAL_LAYER, new QtLayerBorrower);
    $layer = CAMetalLayer::fromPointer($surface->handle('layer'));

    expect($surface->kind)->toBe(SurfaceKind::METAL_LAYER)
        ->and(count($canvas->native()->children()))->toBe($children + 1)
        ->and($layer->pixelFormat())->toBeInstanceOf(MTLPixelFormat::class)
        ->and($layer->contentsScale())->toBe($canvas->native()->devicePixelRatioF())
        ->and($surface->size())->toBe($canvas->pixelSize())
        ->and($canvas->lent())->toBe($surface);
});

it('lends nothing a device it cannot host, naming what it can', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);

    $canvas->lend(SurfaceKind::DMABUF, new QtLayerBorrower);
})->throws(WindowException::class, 'lends no dmabuf surface (it lends: metal-layer, sdl-window, gl-context).');

it('shows metal frames in the window with no pixel through PHP', function (): void {
    $window = driver()->open('main', 320, 240);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);

    $engine = new GpuRenderingEngine(new MetalDevice, ...$canvas->pixelSize(), output: $canvas);
    $shown = 0;
    for ($i = 0; $i < 60; $i++) {
        $engine->frame(function (RenderingEngine $g) use ($i, $canvas): void {
            [$width, $height] = $canvas->pixelSize();
            $g->clear(Color::rgb(16, 24, 32));
            $g->fillEllipse($width * (0.2 + 0.6 * $i / 59), $height / 2, $height / 6, $height / 6, Color::rgb(255, 128, 0));
            $g->text('metal', 8, 8, Color::rgb(255, 255, 255), new Surface\Fonts\ClassicFont);
        });
        $canvas->present();
        $shown += $canvas->boundFramebuffer()->damage() === [] ? 1 : 0;
        pumpFor(0.016);
    }

    expect($canvas->boundFramebuffer())->toBe($engine->framebuffer())
        ->and($shown)->toBeGreaterThan(30)
        ->and($canvas->label()->pixmap()->isNull())->toBeTrue();
    $engine->release();
    expect($canvas->lent())->toBeNull();
});

it('re-targets the engine when the window is resized', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);
    $engine = new GpuRenderingEngine(new MetalDevice, ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $before = $engine->framebuffer();

    $window->native()->resize(400, 300);
    pumpFor(0.2);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $canvas->present();

    expect($engine->framebuffer())->not->toBe($before)
        ->and([$engine->width(), $engine->height()])->toBe($canvas->pixelSize())
        ->and($canvas->lent()?->size())->toBe($canvas->pixelSize());
    $engine->release();
});

it('shows its framebuffer again after a reclaim', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);
    $children = count($canvas->native()->children());
    $own = $canvas->framebuffer('dirty');
    $own->fill(0xFF6600FF);
    $canvas->present();
    $surface = $canvas->lend(SurfaceKind::METAL_LAYER, new QtLayerBorrower);

    $canvas->reclaim();
    pumpFor(0.05);
    $own->setSegment(10, 10, 20, 20, 0x000000FF);
    $canvas->present();

    expect($surface->released())->toBeTrue()
        ->and($canvas->label()->pixmap()->isNull())->toBeFalse()
        ->and($canvas->label()->isVisible())->toBeTrue()
        ->and(count($canvas->native()->children()))->toBe($children);
});

it('reclaims before it is removed', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);
    $surface = $canvas->lend(SurfaceKind::METAL_LAYER, new QtLayerBorrower);

    $canvas->remove();

    expect($surface->released())->toBeTrue()
        ->and($canvas->isRemoved())->toBeTrue();
});

it('lends Qt\'s Metal layer at the canvas\'s pixel size', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);

    // Qt leaves the layer's drawable size at zero until something presents: the device sets it at present().
    $engine = new GpuRenderingEngine(new MetalDevice, ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $canvas->present();
    pumpFor(0.1);
    $surface = $canvas->lent();
    $layer = CAMetalLayer::fromPointer($surface->handle('layer'));

    expect($surface->size())->toBe($canvas->pixelSize())
        ->and($canvas->pixelSize())->toBe([(int) round(300 * $canvas->native()->devicePixelRatioF()), (int) round(200 * $canvas->native()->devicePixelRatioF())])
        ->and([(int) $layer->drawableSize()->width, (int) $layer->drawableSize()->height])->toBe($canvas->pixelSize());
    $engine->release();
});

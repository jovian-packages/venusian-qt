<?php

declare(strict_types=1);

use Jovian\Engines\Sdl3\Sdl3Device;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Windows\WindowException;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\NutsAndBolts\Color;

/*
 * On macOS the canvas lends an SDL window over Qt's NSWindow to the sdl3
 * engine; SDL_GPU's swapchain view is moved into the canvas's Metal QWindow.
 * QtLayerBorrower is tests/Pest.php's. These tests draw real frames through
 * venusian-sdl3 into a window on screen.
 */

function sdlLendingAvailable(): bool
{
    return PHP_OS_FAMILY === 'Darwin' && class_exists(NSView::class) && class_exists(CAMetalLayer::class) && function_exists('SDL_CreateWindowWithProperties');
}

beforeEach(function (): void {
    if (! sdlLendingAvailable()) {
        $this->markTestSkipped('SDL window lending is macOS with ext-appkit, ext-metal and ext-sdl3');
    }
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

afterEach(function (): void {
    if (! sdlLendingAvailable()) {
        return;
    }
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** A device built by hand needs SDL video up first, as renderer('sdl3') brings it up. */
function sdl3Device(): Sdl3Device
{
    SDL_InitSubSystem(SDL_INIT_VIDEO) || throw new RuntimeException('SDL video: '.SDL_GetError());

    return new Sdl3Device;
}

/** A canvas in a shown window, laid out. */
function sdlCanvas(int $width = 320, int $height = 240): array
{
    $window = driver()->open('main', $width, $height);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);

    return [$window, $canvas];
}

/** The NSWindow SDL wraps for a lent surface: Qt's window, found among the application's by address. */
function hostOf(Surface\Contracts\Drawing\LentSurface $surface): NSWindow
{
    $address = SDL_GetPointerProperty(SDL_GetWindowProperties(SDL_Window::fromPointer($surface->handle('window'))), 'SDL.window.cocoa.window', null);
    foreach (NSApplication::sharedApplication()->windows() as $window) {
        if ($window->pointer() === $address) {
            return $window;
        }
    }

    throw new RuntimeException('SDL wraps no window of this application.');
}

/** SDL_GPU's swapchain view, wherever it sits under $root. */
function sdlViewUnder(NSView $root): ?NSView
{
    foreach ($root->subviews() as $view) {
        if ($view->isKindOfClass('SDL3_cocoametalview')) {
            return $view;
        }
        $found = sdlViewUnder($view);
        if (! is_null($found)) {
            return $found;
        }
    }

    return null;
}

it('lends an SDL window when ext-sdl3 is loaded, after the Metal layer', function (): void {
    [$window, $canvas] = sdlCanvas();
    $contents = array_map(fn (NSWindow $window): ?int => $window->contentView()?->pointer(), NSApplication::sharedApplication()->windows());

    // ext-opengl adds the GL context after both.
    expect($canvas->surfaces())->toBe([...qtVulkanKinds(), SurfaceKind::METAL_LAYER, SurfaceKind::SDL_WINDOW, ...(extension_loaded('opengl') ? [SurfaceKind::GL_CONTEXT] : [])]);

    $surface = $canvas->lend(SurfaceKind::SDL_WINDOW, new QtLayerBorrower);
    $host = hostOf($surface);

    // SDL wraps one of Qt's NSWindows and leaves its content view in place.
    expect($surface->kind)->toBe(SurfaceKind::SDL_WINDOW)
        ->and($contents)->toContain($host->contentView()->pointer())
        ->and($surface->size())->toBe($canvas->pixelSize())
        ->and($canvas->lent())->toBe($surface);
});

it('leaves the application, its delegate and its session alone', function (): void {
    [$window, $canvas] = sdlCanvas();

    $canvas->lend(SurfaceKind::SDL_WINDOW, new QtLayerBorrower);
    pumpFor(0.1);

    // Qt's own application delegate keeps the slot: SDL only takes an empty one.
    expect(QCoreApplication::instance())->not->toBeNull()
        ->and(QCoreApplication::instance())->toBe(session()->application())
        ->and(NSApplication::sharedApplication()->delegate()?->className())->not->toBeIn([null, 'SDL3AppDelegate'])
        ->and(session()->connected())->toBeTrue();
});

it('keeps the window\'s responder chain through a lend and a reclaim', function (): void {
    // SDL hooks its listener in as the next responder of the window and its content view.
    [$window, $canvas] = sdlCanvas();
    $engine = new GpuRenderingEngine(sdl3Device(), ...$canvas->pixelSize(), output: $canvas);
    $host = hostOf($canvas->lent());
    $content = $host->contentView();
    $chain = fn (): array => [$host->nextResponder()?->className(), $content->nextResponder()?->className()];
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $canvas->present();

    $during = $chain();
    expect($during)->not->toContain('SDL3Cocoa_WindowListener');

    $engine->release();
    pumpFor(0.05);
    expect($chain())->toBe($during);
});

it('sizes the drawable to the canvas, smaller than its window', function (): void {
    $window = driver()->open('main', 320, 240);
    $column = $window->column('m');
    $column->label('top', 'above');
    $canvas = $column->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);
    $engine = new GpuRenderingEngine(sdl3Device(), ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $canvas->present();

    $drawable = CAMetalLayer::fromPointer(sdlViewUnder(hostOf($canvas->lent())->contentView())->layer()->pointer())->drawableSize();
    expect([(int) $drawable->width, (int) $drawable->height])->toBe($canvas->pixelSize());
    $engine->release();
});

it('destroys a parked SDL window on the session\'s next pump', function (): void {
    [$window, $canvas] = sdlCanvas();
    $engine = new GpuRenderingEngine(sdl3Device(), ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 255)));
    $canvas->present();
    $id = SDL_GetWindowID(SDL_Window::fromPointer($canvas->lent()->handle('window')));

    $engine->release();
    pumpFor(0.05);

    expect(SDL_GetWindowFromID($id))->toBeNull();
});

it('lends one surface at a time, of either kind', function (): void {
    [$window, $canvas] = sdlCanvas();
    $canvas->lend(SurfaceKind::METAL_LAYER, new QtLayerBorrower);

    expect(fn () => $canvas->lend(SurfaceKind::SDL_WINDOW, new QtLayerBorrower))
        ->toThrow(WindowException::class, 'has already lent its metal-layer surface');

    $canvas->reclaim();
    $surface = $canvas->lend(SurfaceKind::SDL_WINDOW, new QtLayerBorrower);
    expect($surface->kind)->toBe(SurfaceKind::SDL_WINDOW);
});

it('shows sdl3 frames in the window with no pixel through PHP', function (): void {
    [$window, $canvas] = sdlCanvas();

    $engine = new GpuRenderingEngine(sdl3Device(), ...$canvas->pixelSize(), output: $canvas);
    $content = hostOf($canvas->lent())->contentView();
    $shown = 0;
    for ($i = 0; $i < 60; $i++) {
        $engine->frame(function (RenderingEngine $g) use ($i, $canvas): void {
            [$width, $height] = $canvas->pixelSize();
            $g->clear(Color::rgb(32, 16, 24));
            $g->fillEllipse($width * (0.2 + 0.6 * $i / 59), $height / 2, $height / 6, $height / 6, Color::rgb(0, 200, 255));
            $g->text('sdl3', 8, 8, Color::rgb(255, 255, 255), new Surface\Fonts\ClassicFont);
        });
        $canvas->present();
        $shown += $canvas->boundFramebuffer()->damage() === [] ? 1 : 0;
        pumpFor(0.016);
    }

    // SDL's swapchain view sits inside the canvas's Metal QWindow, its drawable at the canvas's pixels.
    $view = sdlViewUnder($content);
    $drawable = CAMetalLayer::fromPointer($view->layer()->pointer())->drawableSize();
    expect($canvas->boundFramebuffer())->toBe($engine->framebuffer())
        ->and($shown)->toBeGreaterThan(30)
        ->and($view->superview()?->pointer())->not->toBe($content->pointer())
        ->and([(int) $drawable->width, (int) $drawable->height])->toBe($canvas->pixelSize())
        ->and($canvas->label()->pixmap()->isNull())->toBeTrue();
    $engine->release();
    expect($canvas->lent())->toBeNull();
});

it('re-targets the engine when the window is resized', function (): void {
    [$window, $canvas] = sdlCanvas(300, 200);
    $engine = new GpuRenderingEngine(sdl3Device(), ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $canvas->present();
    $content = hostOf($canvas->lent())->contentView();
    $before = $engine->framebuffer();

    $window->native()->resize(400, 300);
    pumpFor(0.2);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $canvas->present();
    pumpFor(0.05);

    $drawable = CAMetalLayer::fromPointer(sdlViewUnder($content)->layer()->pointer())->drawableSize();
    expect($engine->framebuffer())->not->toBe($before)
        ->and([$engine->width(), $engine->height()])->toBe($canvas->pixelSize())
        ->and([(int) $drawable->width, (int) $drawable->height])->toBe($canvas->pixelSize());
    $engine->release();
});

it('shows its framebuffer again after a reclaim', function (): void {
    [$window, $canvas] = sdlCanvas(300, 200);
    $children = count($canvas->native()->children());
    $own = $canvas->framebuffer('dirty');
    $own->fill(0xFF6600FF);
    $canvas->present();
    $engine = new GpuRenderingEngine(sdl3Device(), ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 255)));
    $canvas->present();
    $content = hostOf($canvas->lent())->contentView();
    pumpFor(0.05);

    $engine->release();
    pumpFor(0.05);
    $own->setSegment(10, 10, 20, 20, 0x000000FF);
    $canvas->present();
    pumpFor(0.05);

    expect($canvas->lent())->toBeNull()
        ->and($canvas->label()->pixmap()->isNull())->toBeFalse()
        ->and($canvas->label()->isVisible())->toBeTrue()
        ->and(count($canvas->native()->children()))->toBe($children)
        ->and(sdlViewUnder($content))->toBeNull();
});

it('reclaims before it is removed', function (): void {
    [$window, $canvas] = sdlCanvas();
    $surface = $canvas->lend(SurfaceKind::SDL_WINDOW, new QtLayerBorrower);

    $canvas->remove();

    expect($surface->released())->toBeTrue()
        ->and($canvas->isRemoved())->toBeTrue();
});

it('destroys its SDL window only once the device has let go of it', function (string $order): void {
    // SDL requires a window released from its GPU device before it is destroyed.
    [$window, $canvas] = sdlCanvas();
    $device = sdl3Device();
    $engine = new GpuRenderingEngine($device, ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 255)));
    $canvas->present();
    $content = hostOf($canvas->lent())->contentView();
    $id = SDL_GetWindowID(SDL_Window::fromPointer($canvas->lent()->handle('window')));

    if ($order === 'reclaim first') {
        $canvas->reclaim();
        pumpFor(0.05);
        expect(SDL_GetWindowFromID($id))->not->toBeNull();
        $device->release();
    } else {
        $device->release();
        $canvas->reclaim();
    }
    pumpFor(0.05);
    $canvas->framebuffer('dirty')->fill(0x000000FF);
    $canvas->present();

    expect(SDL_GetWindowFromID($id))->toBeNull()
        ->and(sdlViewUnder($content))->toBeNull();
})->with(['reclaim first', 'device first']);

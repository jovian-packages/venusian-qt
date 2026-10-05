<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Primitives\QtCanvas;
use Surface\Contracts\Windows\WindowException;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

afterEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** @return array{int, int} The pixmap a canvas's label shows, in pixels. */
function shown(QtCanvas $canvas): array
{
    $pixmap = $canvas->label()->pixmap();

    return [$pixmap->width(), $pixmap->height()];
}

it('lays a canvas out like any view and measures it in device pixels', function (): void {
    $window = driver()->open('main', 300, 200);
    $main = $window->column('m');
    $main->label('title', 'Canvas');
    $canvas = $main->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);

    [$width, $height] = $canvas->size();
    $scale = $canvas->native()->devicePixelRatioF();

    expect($canvas)->toBeInstanceOf(QtCanvas::class)
        ->and($canvas->native())->toBeInstanceOf(QWidget::class)
        ->and($canvas->label())->toBeInstanceOf(QLabel::class)
        ->and($width)->toBeGreaterThan(250)
        ->and($height)->toBeGreaterThan(100)
        ->and($canvas->pixelSize())->toBe([(int) round($width * $scale), (int) round($height * $scale)])
        ->and($canvas->label()->pixmap()->isNull())->toBeTrue();
});

it('shows its framebuffer as the label\'s pixmap, only when something was drawn', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);

    $buffer = $canvas->framebuffer('dirty');
    $buffer->fill(0xFF6600FF);
    $canvas->present();
    pumpFor(0.05);
    expect(shown($canvas))->toBe($canvas->pixelSize());

    $canvas->framebuffer('dirty', 8, 4);                             // a new framebuffer: shown once, then only with damage
    $canvas->present();
    expect(shown($canvas))->toBe([8, 4]);
    $canvas->label()->setPixmap(null);
    $canvas->present();                                              // nothing drawn since: the label is left alone
    expect($canvas->label()->pixmap()->isNull())->toBeTrue();

    $canvas->boundFramebuffer()->setPixel(0, 0, 0x000000FF);
    $canvas->present();
    expect(shown($canvas))->toBe([8, 4]);
});

it('stretches a framebuffer of another size over the view, without the view taking its size', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);
    $laid_out = $canvas->size();

    $canvas->framebuffer('full', 32, 24)->fill(0x3366CCFF);
    $canvas->present();
    pumpFor(0.1);
    expect(shown($canvas))->toBe([32, 24])
        ->and($canvas->size())->toBe($laid_out);

    $canvas->framebuffer('full', 1200, 900)->fill(0x3366CCFF);       // larger than the window: the layout still decides
    $canvas->present();
    pumpFor(0.1);
    expect(shown($canvas))->toBe([1200, 900])
        ->and($canvas->size())->toBe($laid_out);
});

it('takes a framebuffer before the window is shown when given a size, and goes with its view', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $canvas->framebuffer('ring', 16, 8)->fill(0xFFFFFFFF);
    $canvas->boundFramebuffer()->present();
    $canvas->present();

    expect(shown($canvas))->toBe([16, 8]);

    $canvas->remove();
    expect(fn () => $canvas->present())->toThrow(WindowException::class, 'was removed')
        ->and($window->view('m.view'))->toBeNull();
});

it('pipes an ext-fb framebuffer to the label by address', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpFor(0.2);

    $buffer = $canvas->framebuffer('dirty', driver: 'extended');
    $buffer->fill(0xFF6600FF);
    $canvas->present();
    pumpFor(0.05);

    expect($buffer->pointer())->not->toBe(0)
        ->and(shown($canvas))->toBe([$buffer->viewportWidth(), $buffer->viewportHeight()]);
})->skip(! class_exists(FbBuffer::class), 'ext-fb is not loaded in this PHP.');

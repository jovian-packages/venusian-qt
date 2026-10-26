<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Primitives\QtScrollBar;
use Surface\Contracts\Windows\Mail\View\ScrollChanged;

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

/** The scroll mail posted since the last read, latest-wins mail flushed first. */
function scrollMail(): array
{
    session()->flushLatest();

    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof ScrollChanged));
}

function scrollBar(): array
{
    $window = driver()->open('main', 200, 300);
    $bar = $window->column('m')->scrollBar('bar');
    $bar->setRange(1000, 100);

    return [$window, $bar];
}

it('is a scroll bar over the range less a page, its page and step', function (): void {
    [$window, $bar] = scrollBar();

    $bar->setStep(10)->setValue(450);

    expect($bar)->toBeInstanceOf(QtScrollBar::class)
        ->and($bar->native())->toBeInstanceOf(QScrollBar::class)
        ->and($bar->native()->orientation())->toBe(Qt\Orientation::VERTICAL)
        ->and([$bar->native()->minimum(), $bar->native()->maximum(), $bar->native()->pageStep(), $bar->native()->singleStep(), $bar->native()->value()])->toBe([0, 900, 100, 10, 450])
        ->and(scrollMail())->toBe([]);

    $bar->setRange(50, 100);
    expect([$bar->native()->maximum(), $bar->native()->value()])->toBe([0, 0]);
    $window->close();
});

it('pulls the value in when the range shrinks under it, and posts nothing', function (): void {
    [$window, $bar] = scrollBar();
    $bar->setValue(900);

    $bar->setRange(500, 100);

    expect($bar->value())->toBe(400)
        ->and($bar->native()->value())->toBe(400)
        ->and(scrollMail())->toBe([]);
    $window->close();
});

it('posts the user\'s move', function (): void {
    [$window, $bar] = scrollBar();

    // A drag, a track click, a wheel or a key moves the slider outside the driver's guard.
    $bar->native()->setValue(312);

    expect(scrollMail())->toEqual([new ScrollChanged('main', 'm.bar', $bar->uuid(), 312)])
        ->and($bar->value())->toBe(312);
    $window->close();
});

it('lies across for a horizontal bar', function (): void {
    $window = driver()->open('main', 200, 300);

    expect($window->column('m')->scrollBar('bar', false)->native()->orientation())->toBe(Qt\Orientation::HORIZONTAL);
    $window->close();
});

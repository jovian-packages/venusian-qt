<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Windows\QtWindow;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\Mail\WindowFocused;
use Surface\Contracts\Windows\WindowException;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

it('opens a titled window under its name', function (): void {
    $window = driver()->open('main', 480, 320);

    expect($window)->toBeInstanceOf(QtWindow::class)
        ->and($window->name())->toBe('main')
        ->and($window->title())->toBe('main')
        ->and($window->isOpen())->toBeTrue()
        ->and($window->native())->toBeInstanceOf(QMainWindow::class)
        ->and([$window->native()->width(), $window->native()->height()])->toBe([480, 320])
        ->and($window->native()->centralWidget())->toBe($window->centralWidget())
        ->and($window->content())->toBeNull()
        ->and($window->native()->testAttribute(Qt\WidgetAttribute::DELETE_ON_CLOSE))->toBeTrue()
        ->and(driver()->get('main'))->toBe($window)
        ->and(driver()->all())->toBe(['main' => $window]);

    $window->setTitle('Main Window');
    expect($window->title())->toBe('Main Window');
});

it('refuses a second window under the same name', function (): void {
    driver()->open('main', 100, 100);

    expect(fn () => driver()->open('main', 100, 100))->toThrow(WindowException::class, 'already open');
});

it('posts WindowFocused when presented and active', function (): void {
    $window = driver()->open('main', 320, 200)->present();
    pumpFor(0.5);

    expect($window->isActive())->toBeTrue()
        ->and(takeMail(session()))->toContainEqual(new WindowFocused('main'));
});

it('closes once, posts WindowClosed once, Qt deletes it, and it is gone afterwards', function (): void {
    $window = driver()->open('main', 320, 200)->present();
    pumpFor(0.1);
    takeMail(session());
    $native = $window->native();

    $window->close();
    $window->close();
    pumpFor(0.05);

    expect(array_values(array_filter(takeMail(session()), fn ($m) => $m instanceof WindowClosed)))->toEqual([new WindowClosed('main')])
        ->and($window->isOpen())->toBeFalse()
        ->and(driver()->has('main'))->toBeFalse()
        ->and(fn () => $window->title())->toThrow(WindowException::class, 'closed')
        ->and(fn () => $native->isVisible())->toThrow(QtException::class, 'deleted');
});

it('closes a window never shown the same way', function (): void {
    $window = driver()->open('main', 320, 200);

    $window->close();

    expect(takeMail(session()))->toEqual([new WindowClosed('main')])
        ->and(driver()->has('main'))->toBeFalse();
});

it('closes every window', function (): void {
    driver()->open('a', 100, 100);
    driver()->open('b', 100, 100);

    driver()->closeAll();

    expect(driver()->all())->toBe([])
        ->and(array_values(array_filter(takeMail(session()), fn ($m) => $m instanceof WindowClosed)))->toEqual([new WindowClosed('a'), new WindowClosed('b')]);
});

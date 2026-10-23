<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Windows\QtWindow;
use Surface\Contracts\Windows\Mail\WindowCloseRequested;
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
    // Whether the OS makes it active is not the driver's to decide: macOS declines a process's
    // activation while another app holds focus. The activation Qt delivers when it does is sent here.
    QCoreApplication::sendEvent($window->native(), new QEvent(QEvent\Type::WINDOW_ACTIVATE));

    expect($window->native()->isVisible())->toBeTrue()
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

it('keeps a confirming window open on the user close and posts WindowCloseRequested', function (): void {
    $window = driver()->open('main', 320, 200)->present();
    pumpFor(0.1);
    takeMail(session());
    $closing = fn (): array => array_values(array_filter(takeMail(session()), fn ($m) => $m instanceof WindowClosed || $m instanceof WindowCloseRequested));

    expect($window->confirmClose())->toBe($window)
        ->and($window->isConfirmingClose())->toBeTrue();

    $window->native()->close();
    pumpFor(0.05);
    expect($closing())->toEqual([new WindowCloseRequested('main')])
        ->and($window->isOpen())->toBeTrue();

    $window->close();
    expect($closing())->toEqual([new WindowClosed('main')])
        ->and($window->isOpen())->toBeFalse();
});

it('closes on the user close when it does not confirm close', function (): void {
    $window = driver()->open('main', 320, 200)->present();
    pumpFor(0.1);
    takeMail(session());
    $window->native()->close();

    expect(array_values(array_filter(takeMail(session()), fn ($m) => $m instanceof WindowClosed)))->toEqual([new WindowClosed('main')])
        ->and($window->isOpen())->toBeFalse();
});

it('closes every window', function (): void {
    driver()->open('a', 100, 100);
    driver()->open('b', 100, 100);

    driver()->closeAll();

    expect(driver()->all())->toBe([])
        ->and(array_values(array_filter(takeMail(session()), fn ($m) => $m instanceof WindowClosed)))->toEqual([new WindowClosed('a'), new WindowClosed('b')]);
});

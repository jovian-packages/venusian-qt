<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\WindowCloseRequested;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\WindowException;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** The WindowClosed and WindowCloseRequested mail the session holds, as 'closed:<name>' / 'requested:<name>', in order. */
function closeMail(): array
{
    return array_values(array_map(
        fn (object $mail): string => ($mail instanceof WindowCloseRequested ? 'requested:' : 'closed:').$mail->window,
        array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof WindowClosed || $mail instanceof WindowCloseRequested),
    ));
}

it('makes a child window-modal to its parent, a sheet on macOS and a dialog elsewhere', function (): void {
    $main = driver()->open('main', 480, 320);
    $child = driver()->open('child', 300, 120, null, $main);
    $type = PHP_OS_FAMILY === 'Darwin' ? Qt\WindowType::SHEET : Qt\WindowType::DIALOG;

    expect($child->native()->windowModality())->toBe(Qt\WindowModality::WINDOW_MODAL)
        ->and($child->native()->parentWidget())->toBe($main->native())
        ->and($child->native()->isWindow())->toBeTrue()
        ->and($child->native()->windowFlags() & Qt\WindowType::WINDOW_TYPE_MASK->value)->toBe($type->value)
        ->and($child->parent())->toBe($main)
        ->and($main->parent())->toBeNull();
});

it('presents the parent first when the parent is not shown', function (): void {
    $main = driver()->open('main', 480, 320);
    driver()->open('child', 300, 120, null, $main)->present();
    pumpFor(0.2);

    expect($main->native()->isVisible())->toBeTrue();
});

it('closes the child, which ends its modality, and the parent closes after it', function (): void {
    $main = driver()->open('main', 480, 320);
    $child = driver()->open('child', 300, 120, null, $main);
    $child->present();
    pumpFor(0.2);
    expect(QApplication::activeModalWidget())->toBe($child->native());

    $child->close();
    pumpFor(0.1);

    expect($child->isOpen())->toBeFalse()
        ->and($child->native(...))->toThrow(WindowException::class)
        ->and(QApplication::activeModalWidget())->toBeNull();

    $main->native()->close();
    pumpFor(0.1);

    expect(closeMail())->toBe(['closed:child', 'closed:main']);
});

it('refuses the user close of a parent that has an open child, presented or not', function (bool $presented): void {
    $main = driver()->open('main', 480, 320);
    $main->present();
    $child = driver()->open('child', 300, 120, null, $main);
    if ($presented) {
        $child->present();
    }
    pumpFor(0.2);
    closeMail();

    $main->native()->close();
    pumpFor(0.1);

    expect(closeMail())->toBe([])
        ->and($main->isOpen())->toBeTrue()
        ->and($child->isOpen())->toBeTrue();
})->with(['not presented' => false, 'presented' => true]);

it('refuses a menu bar for a child', function (): void {
    $main = driver()->open('main', 480, 320);
    $child = driver()->open('child', 300, 120, null, $main);

    expect(fn () => $child->setMenuBar('main'))->toThrow(WindowException::class, "Window 'child' is a child, so it cannot have a menu bar.");
});

it('closes the child first when the parent closes, with no close request from a confirming child', function (): void {
    $main = driver()->open('main', 480, 320);
    $child = driver()->open('child', 300, 120, null, $main)->confirmClose();
    $child->present();
    pumpFor(0.2);
    $main->close();
    pumpFor(0.1);

    expect(closeMail())->toBe(['closed:child', 'closed:main'])
        ->and($child->isOpen())->toBeFalse();
});

it('refuses a child as parent, a parent with an open child, and a menu', function (): void {
    $main = driver()->open('main', 480, 320);
    $child = driver()->open('child', 300, 120, null, $main);

    expect(fn () => driver()->open('grandchild', 100, 100, null, $child))->toThrow(WindowException::class, "'child' is a child")
        ->and(fn () => driver()->open('second', 100, 100, null, $main))->toThrow(WindowException::class, "already has the open child 'child'")
        ->and(fn () => driver()->open('menu', 100, 100, driver()->profile('tools'), driver()->open('other', 100, 100)))->toThrow(WindowException::class, 'cannot have a menu bar');
});

it('posts nothing for open with a parent, present and confirmClose', function (): void {
    $main = driver()->open('main', 480, 320);
    driver()->open('child', 300, 120, null, $main)->confirmClose()->present();
    pumpFor(0.2);

    expect(closeMail())->toBe([]);
});

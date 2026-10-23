<?php

declare(strict_types=1);

use QEvent\Type;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\Windows\Mail\WindowKeyPressed;

// Key events sent to the focused widget, as Qt delivers them. A QKeyEvent made in PHP carries no
// native codes, so the physical key reads Key::UNKNOWN here, and sendEvent never reaches Qt's
// shortcut map: the real key and a menu accelerator are checked by eye.

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** The window key mail the session holds, in order. */
function windowKeys(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof WindowKeyPressed));
}

/** A key press and release sent to $widget. */
function sendKey(QWidget $widget, Qt\Key $key, int $modifiers = 0, string $text = ''): void
{
    QCoreApplication::sendEvent($widget, new QKeyEvent(Type::KEY_PRESS, $key->value, $modifiers, $text));
    QCoreApplication::sendEvent($widget, new QKeyEvent(Type::KEY_RELEASE, $key->value, $modifiers, $text));
    pumpFor(0.05);
}

/** A shown window with two text inputs, the first focused. */
function keysWindow(): Jovian\Toolkits\Qt\Windows\QtWindow
{
    $main = driver()->open('main', 480, 320);
    $column = $main->column('m');
    $column->textInput('first');
    $column->textInput('second');
    $main->present();
    pumpFor(0.3);
    $main->view('m.first')->focus();
    pumpFor(0.1);
    takeMail(session());

    return $main;
}

it('posts a key the focused input did not take, with its modifiers and no text for a function key', function (): void {
    $main = keysWindow();
    sendKey($main->view('m.first')->native(), Qt\Key::F5, 0x04000000);
    $mail = windowKeys();

    expect($mail)->toHaveCount(1)
        ->and($mail[0]->window)->toBe('main')
        ->and($mail[0]->key)->toBe(Key::UNKNOWN)
        ->and($mail[0]->character)->toBe('')
        ->and($mail[0]->modifiers->ctrl || $mail[0]->modifiers->meta)->toBeTrue()
        ->and($mail[0]->repeat)->toBeFalse();
    $main->close();
});

it('posts nothing for a modifier pressed alone, as AppKit sends none', function (Qt\Key $key): void {
    $main = keysWindow();
    sendKey($main->view('m.first')->native(), $key);

    expect(windowKeys())->toBe([]);
    $main->close();
})->with([Qt\Key::SHIFT, Qt\Key::CONTROL, Qt\Key::META, Qt\Key::ALT, Qt\Key::CAPS_LOCK, Qt\Key::ALT_GR, Qt\Key::SUPER_L, Qt\Key::SUPER_R, Qt\Key::HYPER_L, Qt\Key::HYPER_R]);

it('posts nothing for Return or Enter a focused text input submits', function (Qt\Key $key): void {
    $main = keysWindow();
    sendKey($main->view('m.first')->native(), $key, 0, "\r");

    expect(windowKeys())->toBe([]);
    $main->close();
})->with([Qt\Key::RETURN, Qt\Key::ENTER]);

it('posts nothing for a key the input takes', function (): void {
    $main = keysWindow();
    sendKey($main->view('m.first')->native(), Qt\Key::A, 0, 'a');

    expect(windowKeys())->toBe([])
        ->and($main->view('m.first')->value())->toBe('a');
    $main->close();
});

it('moves focus with Tab and posts no key', function (): void {
    $main = keysWindow();
    sendKey($main->view('m.first')->native(), Qt\Key::TAB);

    expect(windowKeys())->toBe([])
        ->and(QApplication::focusWidget())->toBe($main->view('m.second')->native());
    $main->close();
});

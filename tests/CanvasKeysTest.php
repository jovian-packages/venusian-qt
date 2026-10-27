<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Windows\QtWindow;
use QEvent\Type;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\Windows\Input\EditCommand;
use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Surface\Contracts\Windows\Mail\View\EditCommanded;
use Surface\Contracts\Windows\Mail\View\KeyPressed;
use Surface\Contracts\Windows\Mail\View\KeyReleased;
use Surface\Contracts\Windows\Mail\View\TextCommitted;
use Surface\Contracts\Windows\Mail\WindowKeyPressed;
use Surface\Contracts\Windows\Primitives\ButtonRole;

// Key events sent to the canvas's host widget, as Qt delivers them. A QKeyEvent made in PHP carries
// no native codes, so the physical key reads Key::UNKNOWN here. Modifiers: Shift 0x02000000,
// Control 0x04000000 (Cmd on macOS).

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** A shown window with two text inputs, a focusable canvas `map` and a canvas `editor` that takes Tab; `editor` focused. */
function canvasKeysWindow(): QtWindow
{
    $main = driver()->open('main', 480, 400);
    $column = $main->column('m');
    $column->textInput('first');
    $column->textInput('second');
    $column->canvas('map')->minSize(200, 60)->setFocusable();
    $column->canvas('editor')->minSize(200, 60)->setFocusable(true, takesTab: true);
    $main->present();
    pumpFor(0.3);
    $main->view('m.editor')->focus();
    pumpFor(0.1);
    takeMail(session());

    return $main;
}

/** The canvas key mail the session holds, in order, plus any WindowKeyPressed. */
function canvasKeys(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof KeyPressed || $mail instanceof KeyReleased
        || $mail instanceof TextCommitted || $mail instanceof EditCommanded || $mail instanceof WindowKeyPressed));
}

/** A key press and release sent to a canvas's host widget. */
function canvasKey(QtWindow $main, string $path, Qt\Key $key, int $modifiers = 0, string $text = ''): void
{
    $widget = $main->view($path)->native();
    QCoreApplication::sendEvent($widget, new QKeyEvent(Type::KEY_PRESS, $key->value, $modifiers, $text));
    QCoreApplication::sendEvent($widget, new QKeyEvent(Type::KEY_RELEASE, $key->value, $modifiers, $text));
    pumpFor(0.05);
}

it('posts the key, the committed text and the release for a letter on a focused canvas', function (): void {
    $main = canvasKeysWindow();
    $uuid = $main->view('m.editor')->uuid();
    canvasKey($main, 'm.editor', Qt\Key::A, 0, 'a');

    expect(canvasKeys())->toEqual([
        new KeyPressed('main', 'm.editor', $uuid, Key::UNKNOWN, 'a', new Modifiers(), false),
        new TextCommitted('main', 'm.editor', $uuid, 'a'),
        new KeyReleased('main', 'm.editor', $uuid, Key::UNKNOWN, new Modifiers()),
    ]);
    $main->close();
});

it('posts the platform\'s edit command for a bound key', function (Qt\Key $key, int $modifiers, EditCommand $command): void {
    $main = canvasKeysWindow();
    canvasKey($main, 'm.editor', $key, $modifiers);

    $commands = array_values(array_filter(canvasKeys(), fn (object $mail): bool => $mail instanceof EditCommanded));
    expect($commands)->toHaveCount(1)
        ->and($commands[0]->command)->toBe($command)
        ->and($commands[0]->path)->toBe('m.editor');
    $main->close();
})->with([
    'Left' => [Qt\Key::LEFT, 0, EditCommand::MoveLeft],
    'Shift+Left' => [Qt\Key::LEFT, 0x02000000, EditCommand::SelectLeft],
    'Control+A' => [Qt\Key::A, 0x04000000, EditCommand::SelectAll],
    'Backspace' => [Qt\Key::BACKSPACE, 0, EditCommand::DeleteBackward],
    'Return' => [Qt\Key::RETURN, 0, EditCommand::InsertNewline],
    'Escape with no cancel button' => [Qt\Key::ESCAPE, 0, EditCommand::Cancel],
    'Tab' => [Qt\Key::TAB, 0, EditCommand::InsertTab],
    'Backtab' => [Qt\Key::BACKTAB, 0x02000000, EditCommand::InsertBacktab],
]);

it('posts the macOS emacs keys as AppKit does: Ctrl+K deletes to the line end, Ctrl+Y yanks', function (Qt\Key $key, EditCommand $command): void {
    $main = canvasKeysWindow();
    // macOS: Qt reads the physical Control key as Meta (0x10000000).
    canvasKey($main, 'm.editor', $key, 0x10000000);

    $commands = array_values(array_filter(canvasKeys(), fn (object $mail): bool => $mail instanceof EditCommanded));
    expect(array_map(fn (EditCommanded $mail): EditCommand => $mail->command, $commands))->toBe([$command]);
    $main->close();
})->with([
    'Ctrl+K' => [Qt\Key::K, EditCommand::DeleteToLineEnd],
    'Ctrl+Y' => [Qt\Key::Y, EditCommand::Yank],
])->skip(PHP_OS_FAMILY !== 'Darwin', 'macOS key bindings');

it('posts KeyPressed before the command and no text for a bound key, and no WindowKeyPressed for any', function (): void {
    $main = canvasKeysWindow();
    canvasKey($main, 'm.editor', Qt\Key::TAB);
    canvasKey($main, 'm.editor', Qt\Key::LEFT);

    expect(array_map(fn (object $mail): string => $mail::class, canvasKeys()))->toBe([
        KeyPressed::class, EditCommanded::class, KeyReleased::class,
        KeyPressed::class, EditCommanded::class, KeyReleased::class,
    ]);
    $main->close();
});

it('posts a lone modifier\'s press with its modifier and its release without, from the state the platform reports', function (): void {
    $main = canvasKeysWindow();
    $uuid = $main->view('m.editor')->uuid();
    $widget = $main->view('m.editor')->native();
    // macOS: the press carries the modifiers before the key, the release those during it.
    QCoreApplication::sendEvent($widget, new QKeyEvent(Type::KEY_PRESS, Qt\Key::SHIFT->value, 0, ''));
    QCoreApplication::sendEvent($widget, new QKeyEvent(Type::KEY_RELEASE, Qt\Key::SHIFT->value, 0x02000000, ''));
    pumpFor(0.05);

    expect(canvasKeys())->toEqual([
        new KeyPressed('main', 'm.editor', $uuid, Key::UNKNOWN, '', new Modifiers(shift: true), false),
        new KeyReleased('main', 'm.editor', $uuid, Key::UNKNOWN, new Modifiers()),
    ]);
    $main->close();
});

it('posts a release only for a key whose press it posted, once', function (): void {
    $main = canvasKeysWindow();
    $widget = $main->view('m.editor')->native();
    // An input method took the press (a composing key): its release is not the canvas's.
    QCoreApplication::sendEvent($widget, new QKeyEvent(Type::KEY_RELEASE, Qt\Key::E->value, 0, 'e'));
    canvasKey($main, 'm.editor', Qt\Key::B, 0, 'b');
    QCoreApplication::sendEvent($widget, new QKeyEvent(Type::KEY_RELEASE, Qt\Key::B->value, 0, 'b'));
    pumpFor(0.05);

    expect(array_map(fn (object $mail): string => $mail::class, canvasKeys()))->toBe([KeyPressed::class, TextCommitted::class, KeyReleased::class]);
    $main->close();
});

it('reads a real event\'s native key 0 as the A key on macOS, and an event made in code as unknown', function (): void {
    $made = new QKeyEvent(Type::KEY_PRESS, Qt\Key::A->value, 0, 'a');
    $real = new QKeyEvent(Type::KEY_PRESS, Qt\Key::A->value, 0, 'a');
    $real->setTimestamp(1);

    expect(Jovian\Toolkits\Qt\Windows\QtWindowKeys::keyOf($made))->toBe(Key::UNKNOWN)
        ->and(Jovian\Toolkits\Qt\Windows\QtWindowKeys::keyOf($real))->toBe(PHP_OS_FAMILY === 'Darwin' ? Key::A : Key::UNKNOWN);
});

it('posts the text an input method commits', function (): void {
    $main = canvasKeysWindow();
    $uuid = $main->view('m.editor')->uuid();
    $event = new QInputMethodEvent('', []);
    $event->setCommitString('é');
    QCoreApplication::sendEvent($main->view('m.editor')->native(), $event);
    pumpFor(0.05);

    expect(canvasKeys())->toEqual([new TextCommitted('main', 'm.editor', $uuid, 'é')])
        ->and($main->view('m.editor')->native()->testAttribute(Qt\WidgetAttribute::INPUT_METHOD_ENABLED))->toBeTrue();
    $main->close();
});

it('asks for input method events only while focusable', function (): void {
    $main = canvasKeysWindow();
    $editor = $main->view('m.editor');
    $editor->setFocusable(false);

    expect($editor->native()->testAttribute(Qt\WidgetAttribute::INPUT_METHOD_ENABLED))->toBeFalse();
    $main->close();
});

it('Tab leaves a canvas that does not take it, posting no canvas mail', function (): void {
    $main = canvasKeysWindow();
    $main->view('m.map')->focus();
    pumpFor(0.1);
    takeMail(session());
    canvasKey($main, 'm.map', Qt\Key::TAB);

    expect($main->view('m.map')->hasFocus())->toBeFalse()
        ->and(canvasKeys())->toBe([]);
    $main->close();
});

it('posts nothing from a canvas that is not focused', function (): void {
    $main = canvasKeysWindow();
    canvasKey($main, 'm.map', Qt\Key::A, 0, 'a');

    // Declined by the canvas, the event made in code climbs to the window, which posts its WindowKeyPressed as in C9.
    expect(array_filter(canvasKeys(), fn (object $mail): bool => ! $mail instanceof WindowKeyPressed))->toBe([]);
    $main->close();
});

it('a role key goes to its button through the application filter, not to the focused canvas', function (): void {
    $main = driver()->open('main', 480, 320);
    $main->column('m')->label('title', 'Main');
    $child = driver()->open('child', 300, 160, null, $main);
    $column = $child->column('c');
    $column->canvas('pad')->minSize(100, 40)->setFocusable();
    $column->button('cancel', 'Cancel')->setRole(ButtonRole::CANCEL);
    $child->present();
    pumpFor(0.3);
    $child->view('c.pad')->focus();
    pumpFor(0.1);
    takeMail(session());
    canvasKey($child, 'c.pad', Qt\Key::ESCAPE);
    pumpFor(0.3);

    $mail = takeMail(session());
    expect(array_values(array_filter($mail, fn (object $mail): bool => $mail instanceof ButtonClicked)))->toHaveCount(1)
        ->and(array_filter($mail, fn (object $mail): bool => $mail instanceof KeyPressed || $mail instanceof EditCommanded))->toBe([]);
    $main->close();
});

it('posts a held key as repeated presses and one release: the release X11 sends before each repeat is not the canvas\'s', function (): void {
    $main = canvasKeysWindow();
    $widget = $main->view('m.editor')->native();
    QCoreApplication::sendEvent($widget, new QKeyEvent(Type::KEY_PRESS, Qt\Key::B->value, 0, 'b'));
    QCoreApplication::sendEvent($widget, new QKeyEvent(Type::KEY_RELEASE, Qt\Key::B->value, 0, 'b', true));
    QCoreApplication::sendEvent($widget, new QKeyEvent(Type::KEY_PRESS, Qt\Key::B->value, 0, 'b', true));
    QCoreApplication::sendEvent($widget, new QKeyEvent(Type::KEY_RELEASE, Qt\Key::B->value, 0, 'b'));
    pumpFor(0.05);

    $keys = array_values(array_filter(canvasKeys(), fn (object $mail): bool => $mail instanceof KeyPressed || $mail instanceof KeyReleased));
    expect(array_map(fn (object $mail): string => $mail::class.($mail instanceof KeyPressed && $mail->repeat ? ' repeat' : ''), $keys))
        ->toBe([KeyPressed::class, KeyPressed::class.' repeat', KeyReleased::class]);
    $main->close();
});

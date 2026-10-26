<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Primitives\QtComboBox;
use QEvent\Type;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Surface\Contracts\Windows\Mail\View\TextChanged;
use Surface\Contracts\Windows\Mail\View\TextSubmitted;

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

/** A shown window with a combo and a text input, the combo focused, the mail drained. */
function comboWindow(): array
{
    $window = driver()->open('main', 360, 220);
    $main = $window->column('m');
    $combo = $main->comboBox('path', '/tmp', ['/Users', '/Volumes']);
    $other = $main->textInput('other', 'x');
    $window->present();
    pumpFor(0.3);
    $combo->focus();
    pumpFor(0.1);
    takeMail(session());

    return [$window, $combo, $other];
}

/** A key press and release on $widget, as the user's. */
function comboKey(QWidget $widget, Qt\Key $key): void
{
    QCoreApplication::sendEvent($widget, new QKeyEvent(Type::KEY_PRESS, $key->value, 0));
    QCoreApplication::sendEvent($widget, new QKeyEvent(Type::KEY_RELEASE, $key->value, 0));
}

function shownCompletions(QtComboBox $combo): bool
{
    return $combo->completer()->popup()?->isVisible() === true;
}

it('is an editable combo holding its options and value, and posts nothing for code', function (): void {
    [$window, $combo] = comboWindow();

    $combo->setValue('/Volumes/x');
    $combo->setOptions(['/a', '/b', '/c']);

    expect($combo->native()->isEditable())->toBeTrue()
        ->and($combo->native()->insertPolicy())->toBe(QComboBox\InsertPolicy::NO_INSERT)
        ->and($combo->native()->count())->toBe(3)
        ->and($combo->native()->currentText())->toBe('/Volumes/x')
        ->and(takeMail(session()))->toBe([]);
    $window->close();
});

it('starts with its value, not its first option', function (): void {
    $window = driver()->open('main', 360, 220);
    $combo = $window->column('m')->comboBox('path', '/tmp', ['/Users', '/Volumes']);

    expect($combo->native()->currentText())->toBe('/tmp')
        ->and($combo->value())->toBe('/tmp');
    $window->close();
});

it('posts TextChanged for typing', function (): void {
    [$window, $combo] = comboWindow();

    QCoreApplication::sendEvent($combo->native()->lineEdit(), new QKeyEvent(Type::KEY_PRESS, Qt\Key::A->value, 0, 'a'));

    expect(takeMail(session()))->toEqual([new TextChanged('main', 'm.path', $combo->uuid(), '/tmpa')])
        ->and($combo->value())->toBe('/tmpa');
    $window->close();
});

it('shows completions under the focused field, and drops them on a field without focus', function (): void {
    [$window, $combo, $other] = comboWindow();

    $combo->setCompletions(['/Users/angel', '/Users/Shared']);
    pumpFor(0.1);
    expect(shownCompletions($combo))->toBeTrue()
        ->and($combo->completionModel()->stringList())->toBe(['/Users/angel', '/Users/Shared'])
        ->and($combo->completions())->toBe(['/Users/angel', '/Users/Shared']);

    $combo->setCompletions([]);
    pumpFor(0.1);
    expect(shownCompletions($combo))->toBeFalse();

    $other->focus();
    pumpFor(0.1);
    $combo->setCompletions(['/Users/angel']);
    expect(shownCompletions($combo))->toBeFalse()
        ->and($combo->completions())->toBe([]);
    $window->close();
});

it('chooses the highlighted completion with Down and Return', function (): void {
    [$window, $combo] = comboWindow();
    $combo->setCompletions(['/Users/angel', '/Users/Shared']);
    pumpFor(0.1);

    comboKey($combo->completer()->popup(), Qt\Key::DOWN);
    comboKey($combo->completer()->popup(), Qt\Key::RETURN);
    pumpFor(0.1);

    expect(takeMail(session()))->toEqual([new SelectionChanged('main', 'm.path', $combo->uuid(), -1, '/Users/angel')])
        ->and($combo->value())->toBe('/Users/angel')
        ->and($combo->native()->currentText())->toBe('/Users/angel')
        ->and(shownCompletions($combo))->toBeFalse()
        ->and($combo->completions())->toBe([]);
    $window->close();
});

it('posts TextSubmitted for Return with no completions showing', function (): void {
    [$window, $combo] = comboWindow();

    comboKey($combo->native()->lineEdit(), Qt\Key::RETURN);

    expect(takeMail(session()))->toEqual([new TextSubmitted('main', 'm.path', $combo->uuid(), '/tmp')]);
    $window->close();
});

it('posts only TextSubmitted for Return on text that matches an option', function (): void {
    [$window, $combo] = comboWindow();
    $combo->setValue('/Volumes');

    comboKey($combo->native()->lineEdit(), Qt\Key::RETURN);

    expect(takeMail(session()))->toEqual([new TextSubmitted('main', 'm.path', $combo->uuid(), '/Volumes')]);
    $window->close();
});

it('hides on focus loss, with no mail', function (): void {
    [$window, $combo, $other] = comboWindow();
    $combo->setCompletions(['/Users/angel']);
    pumpFor(0.1);

    $other->native()->setFocus();
    pumpFor(0.1);

    expect(shownCompletions($combo))->toBeFalse()
        ->and($combo->completions())->toBe([])
        ->and(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof SelectionChanged || $mail instanceof TextSubmitted))->toBe([]);
    $window->close();
});

it('replaces shown rows with nothing highlighted and no mail', function (): void {
    [$window, $combo] = comboWindow();
    $combo->setCompletions(['/Users/angel', '/Users/Shared']);
    pumpFor(0.1);
    comboKey($combo->completer()->popup(), Qt\Key::DOWN);

    $combo->setCompletions(['/Volumes/a']);
    pumpFor(0.1);

    expect($combo->completionModel()->stringList())->toBe(['/Volumes/a'])
        ->and(shownCompletions($combo))->toBeTrue()
        ->and(takeMail(session()))->toBe([]);
    comboKey($combo->completer()->popup(), Qt\Key::RETURN);
    expect(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof SelectionChanged))->toBe([]);
    $window->close();
});

it('hides on Escape', function (): void {
    [$window, $combo] = comboWindow();
    $combo->setCompletions(['/Users/angel']);
    pumpFor(0.1);

    comboKey($combo->completer()->popup(), Qt\Key::ESCAPE);
    pumpFor(0.1);

    expect(shownCompletions($combo))->toBeFalse()
        ->and($combo->completions())->toBe([])
        ->and(takeMail(session()))->toBe([]);
    $window->close();
});

it('posts the option index for a step to the next option, and no TextChanged', function (): void {
    [$window, $combo] = comboWindow();

    // Down in the field steps the combo to its next option: the line edit leaves the key to the combo,
    // where Qt delivers it next (sendEvent does not pass an ignored key on).
    comboKey($combo->native(), Qt\Key::DOWN);
    pumpFor(0.1);

    expect(takeMail(session()))->toEqual([new SelectionChanged('main', 'm.path', $combo->uuid(), 1, '/Volumes')])
        ->and($combo->value())->toBe('/Volumes');
    $window->close();
});

<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Windows\QtWindow;
use Surface\Contracts\Windows\Mail\View\FocusGained;
use Surface\Contracts\Windows\Mail\View\FocusLost;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\WindowException;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** The focus mail the session holds, in order, as short strings. */
function focusMail(): array
{
    return array_values(array_filter(array_map(fn (object $mail): ?string => match (true) {
        $mail instanceof FocusGained => "gained:{$mail->path}",
        $mail instanceof FocusLost => "lost:{$mail->path}",
        default => null,
    }, takeMail(session()))));
}

/** A shown window with two text inputs. */
function focusWindow(): QtWindow
{
    $main = driver()->open('main', 480, 320);
    $column = $main->column('m');
    $column->textInput('first');
    $column->textInput('second');
    $main->present();
    pumpFor(0.3);

    return $main;
}

it('focuses a shown input through Qt and posts nothing', function (): void {
    $main = focusWindow();
    $first = $main->view('m.first');
    $first->focus();
    pumpFor(0.2);

    expect(QApplication::focusWidget())->toBe($first->native())
        ->and($first->hasFocus())->toBeTrue()
        ->and($main->view('m.second')->hasFocus())->toBeFalse()
        ->and($main->focused())->toBe($first)
        ->and(focusMail())->toBe([]);
    $main->close();
});

it('focuses a text area and a table, and refuses a label', function (): void {
    $main = driver()->open('main', 480, 320);
    $column = $main->column('m');
    $area = $column->textArea('area');
    $table = $column->table('table', [new TableColumn('name', 'Name')], [['name' => 'a']]);
    $label = $column->label('label', 'Name');
    $main->present();
    pumpFor(0.3);

    $area->focus();
    pumpFor(0.1);
    expect(QApplication::focusWidget())->toBe($area->native())
        ->and($area->hasFocus())->toBeTrue();

    $table->focus();
    pumpFor(0.1);
    expect($table->hasFocus())->toBeTrue()
        ->and($main->focused())->toBe($table)
        ->and(fn () => $label->focus())->toThrow(WindowException::class, "'m.label' cannot take focus.");
    $main->close();
});

it('refuses focus to a canvas until it is focusable', function (): void {
    $main = driver()->open('main', 480, 320);
    $canvas = $main->column('m')->canvas('map');
    $main->present();
    pumpFor(0.3);

    expect(fn () => $canvas->focus())->toThrow(WindowException::class, "'m.map' cannot take focus.");

    $canvas->setFocusable();
    $canvas->focus();
    pumpFor(0.1);
    expect($canvas->hasFocus())->toBeTrue()
        ->and($canvas->native()->focusPolicy())->toBe(Qt\FocusPolicy::STRONG_FOCUS);
    $main->close();
});

it('keeps Tab on a canvas that takes it, and lets it through otherwise', function (): void {
    $main = driver()->open('main', 480, 320);
    $column = $main->column('m');
    $canvas = $column->canvas('editor');
    $after = $column->textInput('after');
    $main->present();
    pumpFor(0.3);
    $canvas->setFocusable(true, takesTab: true);
    $canvas->focus();
    pumpFor(0.1);

    QCoreApplication::sendEvent($canvas->native(), new QKeyEvent(QEvent\Type::KEY_PRESS, Qt\Key::TAB->value, 0));
    pumpFor(0.1);
    expect($canvas->hasFocus())->toBeTrue();

    $canvas->setFocusable(true);
    QCoreApplication::sendEvent($canvas->native(), new QKeyEvent(QEvent\Type::KEY_PRESS, Qt\Key::TAB->value, 0));
    pumpFor(0.1);
    expect(QApplication::focusWidget())->toBe($after->native());
    $main->close();
});

it('posts the user\'s focus change once, after a focus() that posted nothing', function (): void {
    $main = focusWindow();
    $main->view('m.first')->focus();
    pumpFor(0.2);
    expect(focusMail())->toBe([]);

    // The user's move: Qt itself moves focus, not focus().
    $main->view('m.second')->native()->setFocus();
    pumpFor(0.2);

    expect(focusMail())->toBe(['lost:m.first', 'gained:m.second']);
    $main->close();
});

it('focuses the initial primitive on present, posting nothing', function (): void {
    $main = driver()->open('main', 480, 320);
    $column = $main->column('m');
    $column->textInput('first');
    $second = $column->textInput('second');
    $main->setInitialFocus($second);
    $main->present();
    pumpFor(0.3);

    expect($second->hasFocus())->toBeTrue()
        ->and(focusMail())->toBe([]);
    $main->close();
});

it('posts nothing when code hides or disables the focused input', function (string $change): void {
    $main = focusWindow();
    $first = $main->view('m.first');
    $first->focus();
    pumpFor(0.2);
    takeMail(session());
    $change === 'hide' ? $first->hide() : $first->setEnabled(false);
    pumpFor(0.2);

    // Qt passed focus to the next input: it is the holder the user's next move leaves.
    expect($first->hasFocus())->toBeFalse()
        ->and($main->focused())->toBe($main->view('m.second'))
        ->and(focusMail())->toBe([]);
    $main->close();
})->with(['hide', 'disable']);

it('posts nothing when code hides or disables the container of the focused input', function (string $change): void {
    $main = driver()->open('main', 480, 320);
    $column = $main->column('m');
    $first = $column->row('r')->textInput('first');
    $second = $column->textInput('second');
    $main->present();
    pumpFor(0.3);
    $first->focus();
    pumpFor(0.2);
    takeMail(session());
    $row = $main->view('m.r');
    $change === 'hide' ? $row->hide() : $row->setEnabled(false);
    pumpFor(0.2);

    // Qt passes focus on from a hidden container, and clears it in a disabled one.
    expect($first->hasFocus())->toBeFalse()
        ->and($main->focused())->toBe($change === 'hide' ? $second : null)
        ->and(focusMail())->toBe([]);
    $main->close();
})->with(['hide', 'disable']);

it('lets go of a focused canvas that stops being focusable, posting nothing', function (): void {
    $main = focusWindow();
    $canvas = $main->view('m')->canvas('map')->setFocusable();
    pumpFor(0.2);
    $canvas->focus();
    pumpFor(0.2);
    takeMail(session());
    $canvas->setFocusable(false);
    pumpFor(0.2);

    expect($canvas->hasFocus())->toBeFalse()
        ->and($canvas->native()->hasFocus())->toBeFalse()
        ->and(focusMail())->toBe([]);
    $main->close();
});

it('refuses an initial focus from another window', function (): void {
    $main = focusWindow();
    $other = driver()->open('other', 200, 100);
    $input = $other->column('o')->textInput('x');

    expect(fn () => $main->setInitialFocus($input))->toThrow(WindowException::class, "'o.x' is not in window 'main'.");
    $other->close();
    $main->close();
});

it('posts the user\'s next move after a present that found focus already gone', function (): void {
    $main = driver()->open('main', 480, 320);
    $column = $main->column('m');
    $only = $column->textInput('only');
    $column->label('note', 'Note');
    $main->present();
    pumpFor(0.3);
    $only->focus();
    pumpFor(0.2);
    // The window's only input goes: the window is active and nothing holds focus.
    $only->remove();
    $later = $column->textInput('later');
    pumpFor(0.2);
    $main->present();
    pumpFor(0.2);
    takeMail(session());

    // The user's move: Qt itself moves focus.
    $later->native()->setFocus();
    pumpFor(0.2);

    expect($main->focused())->toBe($later)
        ->and(focusMail())->toBe(['gained:m.later']);
    $main->close();
});

it('forgets a focused primitive that is removed, posting nothing', function (): void {
    $main = focusWindow();
    $first = $main->view('m.first');
    $first->focus();
    pumpFor(0.2);
    takeMail(session());
    $first->remove();
    pumpFor(0.2);

    expect($main->focused())->not->toBe($first)
        ->and(focusMail())->toBe([]);
    $main->close();
});

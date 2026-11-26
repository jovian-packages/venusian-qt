<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\QtWindow;
use Surface\Contracts\Toolkits\Dialogs\Alert;
use Surface\Contracts\Toolkits\Dialogs\AlertStyle;
use Surface\Contracts\Toolkits\Mail\Windows\AlertAnswered;

/** A confirmation whose default is also its cancel button, as one that deletes something asks. */
function confirmation(?string $suppression = null): Alert
{
    return new Alert('Clear your key?', 'It goes back to demo mode.', ['Clear Key', 'Cancel'], default: 1, cancel: 1, style: AlertStyle::Warning, suppression: $suppression, title: 'Stargazer');
}

/** @return array{QMessageBox, list<QPushButton>} the box the window shows and its buttons */
function shownBox(QtWindow $window): array
{
    $box = (fn (): ?QMessageBox => $this->alert)->call($window) ?? throw new RuntimeException("No box on '{$window->name()}'.");
    pumpFor(0.2);

    return [$box, (fn (): array => $this->alert_buttons)->call($window)];
}

/** @return list<AlertAnswered> */
function answers(array $mail): array
{
    return array_values(array_filter($mail, fn (object $m): bool => $m instanceof AlertAnswered));
}

it('opens a window-modal QMessageBox on the window and returns at once', function (): void {
    $window = openWindow();
    takeMail();

    $id = $window->alert(confirmation());
    [$box, $buttons] = shownBox($window);

    expect($id)->toMatch('/^[0-9a-f]{16}$/')
        ->and($box->text())->toBe('Clear your key?')
        ->and($box->informativeText())->toBe('It goes back to demo mode.')
        ->and($box->icon())->toBe(QMessageBox\Icon::WARNING)
        ->and($box->windowModality())->toBe(Qt\WindowModality::WINDOW_MODAL)
        ->and($box->windowTitle())->toBe('Stargazer')
        ->and(array_map(fn (QPushButton $b): string => $b->text(), $buttons))->toBe(['Clear Key', 'Cancel'])
        ->and(answers(takeMail()))->toBe([]);
});

it('posts the index of the button clicked, under the alert\'s id', function (): void {
    $window = openWindow();
    takeMail();
    $id = $window->alert(confirmation());
    [, $buttons] = shownBox($window);

    $buttons[0]->click();
    $answer = answers(mailUntil('AlertAnswered'));

    expect($answer)->toHaveCount(1)
        ->and($answer[0]->id)->toBe($id)
        ->and($answer[0]->window)->toBe('main')
        ->and($answer[0]->button)->toBe(0)
        ->and($answer[0]->suppressed)->toBeFalse()
        ->and($window->isAlerting())->toBeFalse();
});

it('posts no tick when the check box is left alone', function (): void {
    $window = openWindow();
    takeMail();
    $window->alert(confirmation("Don't ask again"));
    [$box, $buttons] = shownBox($window);

    expect($box->checkBox()?->text())->toBe("Don't ask again");
    $buttons[0]->click();

    expect(answers(mailUntil('AlertAnswered'))[0]->suppressed)->toBeFalse();
});

// On macOS Qt shows the box as an NSAlert and, as it ends, sets the check box to the native
// box's state before the click is sent: a tick there is the user's, out of reach of Qt's API.
it('posts whether the check box was ticked', function (): void {
    $window = openWindow();
    takeMail();
    $window->alert(confirmation("Don't ask again"));
    [$box, $buttons] = shownBox($window);

    expect($box->checkBox()?->text())->toBe("Don't ask again");
    $box->checkBox()->setChecked(true);
    $buttons[1]->click();
    $answer = answers(mailUntil('AlertAnswered'));

    expect($answer[0]->button)->toBe(1)
        ->and($answer[0]->suppressed)->toBeTrue();
})->skip(device_os_family() === 'mac', 'Qt shows an NSAlert on macOS: its check box is ticked in the native box.');

it('shows text that looks like HTML as written', function (): void {
    $window = openWindow();
    $window->alert(new Alert('Clear <b>this</b>?', 'Keeps <i>nothing</i>.'));
    [$box] = shownBox($window);

    expect($box->textFormat())->toBe(Qt\TextFormat::PLAIN_TEXT)
        ->and($box->text())->toBe('Clear <b>this</b>?')
        ->and($box->informativeText())->toBe('Keeps <i>nothing</i>.');
});

it('shows an & in a label as written', function (): void {
    $window = openWindow();
    $window->alert(new Alert('Pick', buttons: ['Save & Close', 'Cancel']));
    [, $buttons] = shownBox($window);

    expect($buttons[0]->text())->toBe('Save && Close');
});

it('closes its box and answers -1 when the window closes first', function (): void {
    $window = openWindow();
    takeMail();
    $id = $window->alert(confirmation());
    shownBox($window);

    $window->close();
    $mail = takeMail();
    pumpFor(0.2);

    expect(answers($mail))->toHaveCount(1)
        ->and(answers($mail)[0]->id)->toBe($id)
        ->and(answers($mail)[0]->button)->toBe(-1)
        ->and(mailNames($mail))->toContain('WindowClosed')
        ->and(answers(takeMail()))->toBe([]);
});

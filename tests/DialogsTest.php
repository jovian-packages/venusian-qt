<?php

declare(strict_types=1);

use QEvent\Type;
use Surface\Contracts\Windows\Mail\Services\AlertAnswered;
use Surface\Contracts\Windows\Mail\Services\FilesChosen;
use Surface\Contracts\Windows\Mail\WindowCloseRequested;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\Services\Alert;
use Surface\Contracts\Windows\Services\OpenRequest;
use Surface\Contracts\Windows\Services\SaveRequest;
use Surface\Contracts\Windows\WindowException;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** The dialog and close mail the session holds, in order, as short strings. */
function dialogMail(): array
{
    return array_values(array_filter(array_map(fn (object $mail): ?string => match (true) {
        $mail instanceof AlertAnswered => "alert:{$mail->button}",
        $mail instanceof FilesChosen => 'files:' . implode(',', $mail->paths),
        $mail instanceof WindowClosed => "closed:{$mail->window}",
        $mail instanceof WindowCloseRequested => "requested:{$mail->window}",
        default => null,
    }, takeMail(session()))));
}

/** A shown main window. */
function shownMain(): Jovian\Toolkits\Qt\Windows\QtWindow
{
    $main = driver()->open('main', 480, 320);
    $main->present();
    pumpFor(0.3);

    return $main;
}

/** A key press and release sent to the modal box; Qt on macOS animates the click, so pump 0.3 s. */
function boxKey(Qt\Key $key): void
{
    $box = QApplication::activeModalWidget();
    QCoreApplication::sendEvent($box, new QKeyEvent(Type::KEY_PRESS, $key->value, 0));
    QCoreApplication::sendEvent($box, new QKeyEvent(Type::KEY_RELEASE, $key->value, 0));
    pumpFor(0.3);
}

it('shows an alert as a modal message box and answers Return with the default', function (): void {
    $main = shownMain();
    driver()->dialogs()->alert($main, new Alert('Save?', 'Two files', ['Save', 'Discard', 'Cancel'], default: 1));
    pumpFor(0.3);

    expect(QApplication::activeModalWidget())->toBeInstanceOf(QMessageBox::class)
        ->and(dialogMail())->toBe([]);

    boxKey(Qt\Key::RETURN);

    expect(dialogMail())->toBe(['alert:1']);
    $main->close();
});

it('gives the box text that looks like HTML as escaped rich text, so Qt shows it as written', function (): void {
    $main = shownMain();
    driver()->dialogs()->alert($main, new Alert('Discard <b> in a & b?', "line one\n  two"));
    pumpFor(0.3);
    $box = QApplication::activeModalWidget();

    expect($box->text())->toBe('<qt><p style="white-space:pre-wrap">Discard &lt;b&gt; in a &amp; b?</p></qt>')
        ->and($box->informativeText())->toBe("<qt><p style=\"white-space:pre-wrap\">line one\n  two</p></qt>");
    $main->close();
});

it('doubles & in a button label, so Qt shows it and makes no mnemonic', function (): void {
    expect(Jovian\Toolkits\Qt\Services\QtDialogs::buttonLabel('Save & Close'))->toBe('Save && Close');
});

it('answers Esc with the cancel button', function (): void {
    $main = shownMain();
    driver()->dialogs()->alert($main, new Alert('Save?', null, ['Save', 'Discard', 'Cancel'], default: 1));
    pumpFor(0.3);
    boxKey(Qt\Key::ESCAPE);

    expect(dialogMail())->toBe(['alert:2']);
    $main->close();
});

it('answers Return and Esc with the one button', function (Qt\Key $key): void {
    $main = shownMain();
    driver()->dialogs()->alert($main, new Alert('Done.'));
    pumpFor(0.3);
    boxKey($key);

    expect(dialogMail())->toBe(['alert:0']);
    $main->close();
})->with(['Return' => Qt\Key::RETURN, 'Esc' => Qt\Key::ESCAPE]);

it('answers -1 when the parent closes, then closes it, and nothing more', function (): void {
    $main = shownMain();
    driver()->dialogs()->alert($main, new Alert('Save?'));
    pumpFor(0.3);
    $main->close();
    pumpFor(0.6);

    expect(dialogMail())->toBe(['alert:-1', 'closed:main']);
});

it('answers the alert of a child before the child and its parent close', function (): void {
    $main = shownMain();
    $note = driver()->open('note', 300, 160, null, $main);
    $note->present();
    pumpFor(0.3);
    driver()->dialogs()->alert($note, new Alert('Save?'));
    pumpFor(0.3);
    $main->close();
    pumpFor(0.6);

    expect(dialogMail())->toBe(['alert:-1', 'closed:note', 'closed:main']);
});

it('refuses a second modal on a parent with an open dialog, and keeps the user close', function (): void {
    $main = shownMain();
    driver()->dialogs()->alert($main, new Alert('Save?'));
    pumpFor(0.3);
    $main->confirmClose();

    expect(fn () => driver()->dialogs()->alert($main, new Alert('Again')))->toThrow(WindowException::class, "'main'")
        ->and(fn () => driver()->dialogs()->openFiles($main, new OpenRequest()))->toThrow(WindowException::class, "'main'")
        ->and(fn () => driver()->open('child', 300, 120, null, $main))->toThrow(WindowException::class, "'main'");

    $main->native()->close();
    pumpFor(0.2);

    expect(dialogMail())->toBe([])
        ->and($main->isOpen())->toBeTrue();
    $main->close();
});

it('refuses a dialog on a parent with an open child', function (): void {
    $main = driver()->open('main', 480, 320);
    driver()->open('child', 300, 120, null, $main);

    expect(fn () => driver()->dialogs()->alert($main, new Alert('Save?')))->toThrow(WindowException::class, "'main'");
});

it('answers [] when the parent of a file dialog closes, then closes it, and nothing more', function (string $kind): void {
    $main = shownMain();
    match ($kind) {
        'open' => driver()->dialogs()->openFiles($main, new OpenRequest('Pick', __DIR__)),
        'open several' => driver()->dialogs()->openFiles($main, new OpenRequest(multiple: true)),
        'folder' => driver()->dialogs()->openFiles($main, new OpenRequest(folders: true)),
        'save' => driver()->dialogs()->saveFile($main, new SaveRequest(null, __DIR__, 'c7-save.txt')),
    };
    pumpFor(0.5);
    $main->close();
    pumpFor(0.6);

    expect(dialogMail())->toBe(['files:', 'closed:main']);
})->with(['open', 'open several', 'folder', 'save']);

it('shows a file dialog for a missing directory', function (): void {
    $main = shownMain();
    driver()->dialogs()->openFiles($main, new OpenRequest(directory: '/nonexistent-c7'));
    pumpFor(0.3);

    expect(dialogMail())->toBe([]);
    $main->close();
});

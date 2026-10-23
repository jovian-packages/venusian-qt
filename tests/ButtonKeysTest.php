<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Windows\QtWindow;
use QEvent\Type;
use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Surface\Contracts\Windows\Mail\WindowCloseRequested;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\Primitives\ButtonRole;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\Primitives\TreeNode;

/** Qt::KeypadModifier and Qt::ShiftModifier. */
const KEYPAD = 0x20000000;
const SHIFT = 0x02000000;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** A window with a text input, a tree, a text area, an OK button (DEFAULT) and a Cancel button (CANCEL); presented. */
function rolesWindow(): QtWindow
{
    $window = driver()->open('main', 480, 400);
    $column = $window->column('m');
    $column->textInput('input', '12');
    $column->tree('tree', [new TableColumn('name', 'Name')], [new TreeNode('a', ['name' => 'a'])]);
    $column->textArea('area', 'one');
    $column->button('ok', 'OK')->setRole(ButtonRole::DEFAULT);
    $column->button('cancel', 'Cancel')->setRole(ButtonRole::CANCEL);
    $window->present();
    pumpFor(0.2);
    takeMail(session());

    return $window;
}

/** Focus a primitive's native and drop the mail the focus move posts. */
function focusOn(QtWindow $window, string $path): QWidget
{
    $native = $window->view($path)->native();
    $native->setFocus();
    pumpFor(0.05);
    takeMail(session());

    return $native;
}

/** A key press and release sent to $to, through the application's filters, as a key from the window system is. */
function roleKey(QWidget $to, Qt\Key $key, int $modifiers = 0): void
{
    QCoreApplication::sendEvent($to, new QKeyEvent(Type::KEY_PRESS, $key->value, $modifiers));
    QCoreApplication::sendEvent($to, new QKeyEvent(Type::KEY_RELEASE, $key->value, $modifiers));
    pumpFor(0.05);
}

/** The class and button of each view mail, in order. */
function roleMail(): array
{
    return array_map(
        fn (object $mail): string => substr(strrchr(get_class($mail), '\\'), 1).($mail instanceof ButtonClicked ? ':'.$mail->path : ''),
        viewMail(session()),
    );
}

it('clicks the holder for Return, keypad Enter and Esc, from a text input and from a tree', function (string $path): void {
    $window = rolesWindow();
    $focus = focusOn($window, $path);
    roleKey($focus, Qt\Key::RETURN);
    roleKey($focus, Qt\Key::ENTER, KEYPAD);
    roleKey($focus, Qt\Key::ESCAPE);

    expect(roleMail())->toBe(['ButtonClicked:m.ok', 'ButtonClicked:m.ok', 'ButtonClicked:m.cancel']);
})->with(['m.input', 'm.tree']);

it('gives Return to a focused text area, and Esc to the cancel button', function (): void {
    $window = rolesWindow();
    $area = focusOn($window, 'm.area');
    roleKey($area, Qt\Key::RETURN);
    roleKey($area, Qt\Key::ESCAPE);

    // The new line posts TextChanged.
    expect(roleMail())->toBe(['TextChanged', 'ButtonClicked:m.cancel'])
        ->and(substr_count($window->view('m.area')->value(), "\n"))->toBe(1);
});

it('lets Return with Shift go to the focused widget', function (): void {
    $window = rolesWindow();
    roleKey(focusOn($window, 'm.input'), Qt\Key::RETURN, SHIFT);

    expect(roleMail())->not->toContain('ButtonClicked:m.ok');
});

it('lets Return go to the focused widget when the holder cannot take the key', function (string $case): void {
    $window = rolesWindow();
    $ok = $window->view('m.ok');
    if ($case === 'disabled') {
        $ok->setEnabled(false);
    } elseif ($case === 'hidden group') {
        $ok->remove();
        $window->content()->row('r')->button('ok2', 'OK')->setRole(ButtonRole::DEFAULT);
        $window->view('m.r')->hide();
    } else {
        $ok->remove();
        $tabs = $window->content()->tabs('tabs');
        $tabs->addPage('one', 'One');
        $tabs->addPage('two', 'Two')->button('ok3', 'OK')->setRole(ButtonRole::DEFAULT);
        $tabs->select('one');
    }
    pumpFor(0.1);
    roleKey(focusOn($window, 'm.input'), Qt\Key::RETURN);

    expect(roleMail())->toBe(['TextSubmitted']);
})->with(['disabled', 'hidden group', 'tab not selected']);

it('lets the keys go to an open popup', function (): void {
    $window = rolesWindow();
    $menu = new QMenu();
    $menu->addAction('Item');
    $menu->popup(new QPoint(200, 200));
    pumpFor(0.2);
    roleKey($menu, Qt\Key::RETURN);
    roleKey($menu, Qt\Key::ESCAPE);

    expect(roleMail())->toBe([]);
    $menu->close();
});

it('closes a child that has no cancel button on Esc, or asks when it confirms close', function (): void {
    $main = driver()->open('main', 480, 320);
    $note = driver()->open('note', 200, 100, null, $main);
    $label = $note->column('m')->label('l', 'Note')->native();
    $note->confirmClose()->present();
    pumpFor(0.2);
    takeMail(session());
    $closing = fn (): array => array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof WindowCloseRequested || $mail instanceof WindowClosed));
    roleKey($label, Qt\Key::ESCAPE);

    expect($closing())->toEqual([new WindowCloseRequested('note')])
        ->and($note->isOpen())->toBeTrue();

    $note->confirmClose(false);
    roleKey($label, Qt\Key::ESCAPE);

    expect($closing())->toEqual([new WindowClosed('note')])
        ->and($note->isOpen())->toBeFalse();
});

it('makes the default holder the default push button, and moves it with the role', function (): void {
    $window = rolesWindow();
    $ok = $window->view('m.ok')->native();

    expect($ok->isDefault())->toBeTrue();

    $window->content()->button('other', 'Other')->setRole(ButtonRole::DEFAULT);
    expect($ok->isDefault())->toBeFalse()
        ->and($window->view('m.other')->native()->isDefault())->toBeTrue()
        ->and(viewMail(session()))->toBe([]);
});

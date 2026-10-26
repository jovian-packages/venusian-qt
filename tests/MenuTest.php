<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Bridge\QtBridgeDriver;
use Jovian\Toolkits\Qt\Windows\QtMenuBar;
use QEvent\Type;
use QKeySequence\SequenceFormat;
use Surface\Windows\Menus\Accelerator;
use QAction\MenuRole;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\MenuRadioChosen;
use Surface\Contracts\Windows\Mail\MenuToggled;
use Surface\Contracts\Windows\Mail\QuitRequested;
use Surface\Contracts\Windows\WindowException;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** Choose an item the way its menu does: trigger its action. */
function choose(Jovian\Toolkits\Qt\Windows\QtMenuBar $bar, string $id): void
{
    $bar->action($id)->trigger();
}

it('builds the profile into the window bar: roles, shortcuts and a checkable toggle', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));
    $bar = $window->menuBar();

    expect($bar->bar())->toBe($window->native()->menuBar())
        ->and($bar->bar()->parentWidget())->toBe($window->native())
        ->and($bar->action('app.about')->menuRole())->toBe(MenuRole::ABOUT_ROLE)
        ->and($bar->action('app.quit')->menuRole())->toBe(MenuRole::QUIT_ROLE)
        ->and($bar->action('app.quit')->shortcut()->toString())->toBe('Ctrl+Q')
        ->and($bar->action('view.refresh')->menuRole())->toBe(MenuRole::NO_ROLE)
        ->and($bar->action('view.refresh')->shortcut()->toString())->toBe('Ctrl+Shift+F5')
        ->and($bar->action('view.next')->shortcut()->toString())->toBe('Alt+Down')
        ->and($bar->action('view.tab')->shortcut()->toString())->toBe('Ctrl+]')
        ->and($bar->action('grid')->isCheckable())->toBeTrue()
        ->and($bar->action('grid')->isChecked())->toBeFalse()
        ->and(fn () => $bar->action('nope'))->toThrow(WindowException::class, "No node 'nope'");
});

it('posts MenuActivated for an action item', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));

    choose($window->menuBar(), 'view.refresh');

    expect(takeMail(session()))->toEqual([new MenuActivated('main', 'view.refresh')]);
});

it('flips a toggle and posts MenuToggled with the new state', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));

    choose($window->menuBar(), 'grid');
    choose($window->menuBar(), 'grid');

    expect(takeMail(session()))->toEqual([new MenuToggled('main', 'grid', true), new MenuToggled('main', 'grid', false)])
        ->and($window->isToggled('grid'))->toBeFalse();
});

it('sets a toggle without posting', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));

    $window->setToggle('grid', true);

    expect($window->isToggled('grid'))->toBeTrue()
        ->and(takeMail(session()))->toBe([])
        ->and(fn () => $window->setToggle('view.refresh', true))->toThrow(WindowException::class, 'No toggle item');
});

it('shows About with the configured identity for the About item and posts nothing', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));

    choose($window->menuBar(), 'app.about');
    $box = session()->aboutBox();

    expect(takeMail(session()))->toBe([])
        ->and($box->windowTitle())->toBe('About venusian-qt tests')
        ->and($box->text())->toBe('venusian-qt tests 0.10.0')
        ->and($box->informativeText())->toBe('')
        ->and($box->isModal())->toBeFalse()
        ->and($box->parentWidget())->toBe($window->native())
        ->and($box->isVisible())->toBeTrue();

    $box->close();
    pumpFor(0.05);
    expect(session()->aboutBox())->toBeNull();
});

it('keeps one About box while it is open and builds a new one after close', function (): void {
    $first = session()->showAbout(['name' => 'Probe', 'version' => '1.2.3'], null);
    $again = session()->showAbout(['name' => 'Probe', 'version' => '1.2.4', 'copyright' => '(c) Probe'], null);

    expect($again)->toBe($first)
        ->and($again->text())->toBe('Probe 1.2.4')
        ->and($again->informativeText())->toBe('(c) Probe')
        ->and($again->isVisible())->toBeTrue();

    $again->close();
    pumpFor(0.05);
    $fresh = session()->showAbout(['name' => 'Probe'], null);

    expect(session()->aboutBox())->toBe($fresh)
        ->and($fresh->text())->toBe('Probe');

    $fresh->close();
    pumpFor(0.05);
});

it('posts QuitRequested for Quit and quits nothing', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));

    choose($window->menuBar(), 'app.quit');
    pumpFor(0.05);

    expect(takeMail(session()))->toEqual([new QuitRequested('main')])
        ->and($window->isOpen())->toBeTrue()
        ->and(session()->connected())->toBeTrue();
});

it('swaps the bar by profile name', function (): void {
    $window = driver()->open('main', 320, 200);

    expect($window->menuBar())->toBeNull()
        ->and(fn () => $window->isToggled('grid'))->toThrow(WindowException::class, 'no menu bar');

    $window->setMenuBar('main');
    $old = $window->menuBar()->bar();
    $window->setMenuBar('tools');
    pumpFor(0.05);
    choose($window->menuBar(), 'tools.measure');

    expect($window->native()->menuBar())->toBe($window->menuBar()->bar())
        ->and(fn () => $old->isVisible())->toThrow(QtException::class, 'deleted')
        ->and(takeMail(session()))->toEqual([new MenuActivated('main', 'tools.measure')])
        ->and(fn () => $window->setMenuBar('nope'))->toThrow(WindowException::class, "No menu profile named 'nope'");
});

it('builds the default bar on macOS as a parentless bar whose items post for no window', function (): void {
    driver()->setDefaultMenuBar(driver()->profile('main'));
    $bar = driver()->defaultMenuBar();

    expect($bar->bar()->parentWidget())->toBeNull();

    choose($bar, 'app.quit');
    choose($bar, 'view.refresh');
    choose($bar, 'grid');

    expect(takeMail(session()))->toEqual([new QuitRequested(null), new MenuActivated('', 'view.refresh'), new MenuToggled('', 'grid', true)]);

    $old = $bar->bar();
    driver()->setDefaultMenuBar(driver()->profile('tools'));
    pumpFor(0.05);

    expect(driver()->defaultMenuBar()->action('tools.measure')->text())->toBe('Measure')
        ->and(fn () => $old->isVisible())->toThrow(QtException::class, 'deleted');
})->skip(! QtBridgeDriver::appWideBar(), 'Linux keeps menus inside windows');

it('builds no default bar on Linux, where menus live inside windows', function (): void {
    driver()->setDefaultMenuBar(driver()->profile('main'));

    expect(driver()->defaultMenuBar())->toBeNull();
})->skip(QtBridgeDriver::appWideBar(), 'macOS has an app-wide bar');

it('keeps the default bar built when the same profile is set again', function (): void {
    driver()->setDefaultMenuBar(driver()->profile('tools'));
    driver()->setDefaultMenuBar(driver()->profile('main'));
    $bar = driver()->defaultMenuBar();

    driver()->setDefaultMenuBar(driver()->profile('main'));
    pumpFor(0.05);

    expect(driver()->defaultMenuBar())->toBe($bar)
        ->and(fn () => $bar->bar()->objectName())->not->toThrow(QtException::class);
})->skip(! QtBridgeDriver::appWideBar(), 'Linux keeps menus inside windows');

it('writes each accelerator as Qt portable text, which Qt reads back unchanged', function (string $keys, bool $macos, string $qt): void {
    $text = QtMenuBar::shortcut(Accelerator::parse($keys), $macos);

    expect($text)->toBe($qt)
        ->and(QKeySequence::fromString($text, SequenceFormat::PORTABLE_TEXT)->toString(SequenceFormat::PORTABLE_TEXT))->toBe($qt);
})->with([
    'letter' => ['Primary+A', false, 'Ctrl+A'],
    'digit' => ['Primary+5', false, 'Ctrl+5'],
    'minus' => ['Primary+-', false, 'Ctrl+-'],
    'equals' => ['Primary+=', false, 'Ctrl+='],
    'left bracket' => ['Primary+[', false, 'Ctrl+['],
    'right bracket' => ['Primary+]', false, 'Ctrl+]'],
    'backslash' => ['Primary+\\', false, 'Ctrl+\\'],
    'semicolon' => ['Primary+;', false, 'Ctrl+;'],
    'apostrophe' => ["Primary+'", false, "Ctrl+'"],
    'grave' => ['Primary+`', false, 'Ctrl+`'],
    'comma' => ['Primary+,', false, 'Ctrl+,'],
    'period' => ['Primary+.', false, 'Ctrl+.'],
    'slash' => ['Primary+/', false, 'Ctrl+/'],
    'F12 alone' => ['F12', false, 'F12'],
    'up' => ['Alt+UP', false, 'Alt+Up'],
    'down' => ['Alt+DOWN', false, 'Alt+Down'],
    'left' => ['Alt+LEFT', false, 'Alt+Left'],
    'right' => ['Alt+RIGHT', false, 'Alt+Right'],
    'home' => ['Primary+HOME', false, 'Ctrl+Home'],
    'end' => ['Primary+END', false, 'Ctrl+End'],
    'page up' => ['Primary+PAGE_UP', false, 'Ctrl+PgUp'],
    'page down' => ['Primary+PAGE_DOWN', false, 'Ctrl+PgDown'],
    'space' => ['Primary+SPACE', false, 'Ctrl+Space'],
    'enter' => ['Primary+ENTER', false, 'Ctrl+Return'],
    'tab' => ['Primary+TAB', false, 'Ctrl+Tab'],
    'escape' => ['Primary+ESCAPE', false, 'Ctrl+Esc'],
    'backspace' => ['Primary+BACKSPACE', false, 'Ctrl+Backspace'],
    'delete' => ['Primary+DELETE', false, 'Ctrl+Del'],
    'meta, other platforms' => ['Meta+X', false, 'Meta+X'],
    'ctrl, other platforms' => ['Ctrl+X', false, 'Ctrl+X'],
    'ctrl, macOS' => ['Ctrl+X', true, 'Meta+X'],
    'meta, macOS' => ['Meta+X', true, 'Ctrl+X'],
    'primary and shift, macOS' => ['Primary+Shift+F5', true, 'Ctrl+Shift+F5'],
    'all modifiers, other platforms' => ['Primary+Meta+Alt+Shift+X', false, 'Meta+Ctrl+Alt+Shift+X'],
]);

it('builds a node with enabled false disabled, and the nodes below it', function (): void {
    $bar = driver()->open('main', 320, 200, driver()->profile('main'))->menuBar();

    expect($bar->action('view.locked')->isEnabled())->toBeFalse()
        ->and($bar->action('view.locked.item')->isEnabled())->toBeFalse()
        ->and($bar->action('view.refresh')->isEnabled())->toBeTrue();
});

it('disables a folder and the nodes below it, and restores each own state when it is enabled again', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));
    $bar = $window->menuBar();

    expect($window->setItemEnabled('view', false))->toBe($window)
        ->and($bar->action('view')->isEnabled())->toBeFalse()
        ->and($bar->action('view.refresh')->isEnabled())->toBeFalse()
        ->and($bar->action('view.locked.item')->isEnabled())->toBeFalse()
        ->and($window->isItemEnabled('view'))->toBeFalse()
        ->and($window->isItemEnabled('view.refresh'))->toBeTrue();

    $window->setItemEnabled('view', true);

    expect($bar->action('view.refresh')->isEnabled())->toBeTrue()
        ->and($bar->action('view.locked.item')->isEnabled())->toBeFalse()
        ->and(takeMail(session()))->toBe([]);
});

it('refuses an unknown id, an edit role and the first folder', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('editing'));

    expect(fn () => $window->setItemEnabled('nope', false))->toThrow(WindowException::class, "'nope'")
        ->and(fn () => $window->setItemEnabled('edit.copy', false))->toThrow(WindowException::class, "'Copy'")
        ->and(fn () => $window->setItemEnabled('app', false))->toThrow(WindowException::class, "'App'")
        ->and(fn () => driver()->open('bare', 320, 200)->setItemEnabled('view', false))->toThrow(WindowException::class, 'no menu bar');
});

it('starts a new bar from its own profile', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));
    $window->setItemEnabled('view.refresh', false);

    $window->setMenuBar('main');

    expect($window->isItemEnabled('view.refresh'))->toBeTrue()
        ->and($window->menuBar()->action('view.refresh')->isEnabled())->toBeTrue();
});

it('keeps the states of the default profile, and shows them where a default bar shows', function (): void {
    driver()->setDefaultMenuBar(driver()->profile('tools'));
    driver()->setDefaultMenuBar(driver()->profile('main'));

    driver()->setItemEnabled('view.refresh', false);

    expect(driver()->isItemEnabled('view.refresh'))->toBeFalse()
        ->and(driver()->defaultMenuBar()?->action('view.refresh')->isEnabled() ?? false)->toBeFalse();

    driver()->setDefaultMenuBar(driver()->profile('tools'));
    driver()->setDefaultMenuBar(driver()->profile('main'));

    expect(driver()->isItemEnabled('view.refresh'))->toBeTrue()
        ->and(fn () => (function (): void {
            driver()->setDefaultMenuBar(null);
            driver()->setItemEnabled('view.refresh', false);
        })())->toThrow(WindowException::class, 'default');
});

it('keeps a bar with no parent on macOS, so a focused child never shows its parent bar', function (): void {
    driver()->setDefaultMenuBar(null);
    $empty = driver()->globalMenuBar();

    expect($empty)->toBeInstanceOf(QMenuBar::class)
        ->and($empty->parentWidget())->toBeNull();

    driver()->setDefaultMenuBar(driver()->profile('main'));
    expect(driver()->globalMenuBar())->toBe(driver()->defaultMenuBar()->bar());

    driver()->setDefaultMenuBar(null);
    expect(driver()->globalMenuBar())->toBeInstanceOf(QMenuBar::class)
        ->and(driver()->globalMenuBar())->not->toBe($empty);
    driver()->setDefaultMenuBar(driver()->profile('main'));
})->skip(! QtBridgeDriver::appWideBar(), 'Linux keeps menus inside windows');

it('gives a key to a menu shortcut before a focused text widget takes it', function (): void {
    $window = driver()->open('main', 320, 200);
    $column = $window->column('m');
    $input = $column->textInput('t', 'some text')->native();
    $area = $column->textArea('a', "some\ntext")->native();
    $keys = driver()->menuKeys();
    // Qt asks the focused widget first: an accepted ShortcutOverride keeps the key from the shortcut.
    $override = function (QWidget $widget, int $key, int $modifiers): bool {
        $event = new QKeyEvent(Type::SHORTCUT_OVERRIDE, $key, $modifiers);
        $event->ignore();
        QCoreApplication::sendEvent($widget, $event);

        return $event->isAccepted();
    };
    // Ctrl+Z is undo in both widgets (Qt's Ctrl is Command on macOS), so both claim it alone.
    $ctrlZ = [0x5A, 0x04000000];
    $ctrlLeft = [0x01000012, 0x04000000];
    $altDown = [0x01000015, 0x08000000];

    $watched = [];
    foreach ([$ctrlZ, $ctrlLeft, $altDown] as $key) {
        $watched[] = $override($input, ...$key);
        $watched[] = $override($area, ...$key);
    }
    $keys->detach();
    $alone = [$override($input, ...$ctrlZ), $override($area, ...$ctrlZ)];
    $keys->attach();

    expect($watched)->toBe([false, false, false, false, false, false])
        ->and($alone)->toBe([true, true])
        ->and($keys->watching())->toBeTrue();
});

function caseChecked(QtMenuBar $bar): array
{
    return array_map(fn (string $id): bool => $bar->action($id)->isChecked(), ['case.none' => 'case.none', 'case.unimportant' => 'case.unimportant', 'case.all' => 'case.all']);
}

it('makes a radio group checkable actions in one exclusive group, and sets it without posting', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('choices'));
    $bar = $window->menuBar();

    expect(caseChecked($bar))->toBe(['case.none' => true, 'case.unimportant' => false, 'case.all' => false])
        ->and($bar->radioGroup('ignore-case')->checkedAction())->toBe($bar->action('case.none'));

    $window->setRadio('ignore-case', 'case.all');

    expect(caseChecked($bar))->toBe(['case.none' => false, 'case.unimportant' => false, 'case.all' => true])
        ->and($bar->radioGroup('ignore-case')->checkedAction())->toBe($bar->action('case.all'))
        ->and($window->radio('ignore-case'))->toBe('case.all')
        ->and(takeMail(session()))->toBe([])
        ->and(fn () => $window->setRadio('ignore-case', 'compare.wrap'))->toThrow(WindowException::class, "'compare.wrap' is not in radio group 'ignore-case'.");
});

it('posts MenuRadioChosen for the user\'s choice, and nothing for the item already on', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('choices'));

    $window->menuBar()->action('case.unimportant')->trigger();
    $window->menuBar()->action('case.unimportant')->trigger();

    expect(takeMail(session()))->toEqual([new MenuRadioChosen('main', 'ignore-case', 'case.unimportant')])
        ->and($window->radio('ignore-case'))->toBe('case.unimportant')
        ->and(caseChecked($window->menuBar()))->toBe(['case.none' => false, 'case.unimportant' => true, 'case.all' => false]);
});

it('disables one radio item on its own, and relabels an item and a folder', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('choices'));

    $window->setItemEnabled('case.all', false);
    $window->setItemLabel('case.none', 'Nothing & more');
    $window->setItemLabel('compare', 'Diff');

    expect($window->menuBar()->action('case.all')->isEnabled())->toBeFalse()
        ->and($window->menuBar()->action('case.none')->isEnabled())->toBeTrue()
        ->and($window->menuBar()->action('case.none')->text())->toBe('Nothing && more')
        ->and($window->itemLabel('case.none'))->toBe('Nothing & more')
        ->and($window->menuBar()->action('compare')->text())->toBe('Diff')
        ->and(takeMail(session()))->toBe([]);
});

it('keeps the default bar\'s radios, toggles and labels in the driver, and in the bar where one shows', function (): void {
    driver()->setDefaultMenuBar(driver()->profile('choices'));
    driver()->setRadio('ignore-case', 'case.all');
    driver()->setToggle('compare.wrap', true);
    driver()->setItemLabel('compare.wrap', 'Wrap Lines');

    expect(driver()->radio('ignore-case'))->toBe('case.all')
        ->and(driver()->isToggled('compare.wrap'))->toBeTrue()
        ->and(driver()->itemLabel('compare.wrap'))->toBe('Wrap Lines');
    if (QtBridgeDriver::appWideBar()) {
        expect(caseChecked(driver()->defaultMenuBar())['case.all'])->toBeTrue()
            ->and(driver()->defaultMenuBar()->action('compare.wrap')->isChecked())->toBeTrue()
            ->and(driver()->defaultMenuBar()->action('compare.wrap')->text())->toBe('Wrap Lines');
    }
    driver()->setDefaultMenuBar(driver()->profile('main'));
});

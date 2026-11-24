<?php

declare(strict_types=1);

use QEvent\Type as EventType;
use QAction\MenuRole;
use Jovian\Toolkits\Qt\Qt;
use Jovian\Toolkits\Qt\QtAdapterDriver;
use Jovian\Toolkits\Qt\QtSession;
use Jovian\Toolkits\Qt\QtWindow;
use Jovian\Toolkits\Qt\Menus\QtMenuBar;
use Jovian\Toolkits\Qt\Menus\QtShortcut;
use Surface\Toolkits\Managers\MenuManager;
use Surface\Toolkits\Menus\About;
use Surface\Toolkits\Menus\AboutPanel;
use Surface\Toolkits\Menus\Choice;
use Surface\Toolkits\Menus\Item;
use Surface\Toolkits\Menus\Menu;
use Surface\Toolkits\Menus\MenuBar;
use Surface\Toolkits\Menus\Separator;
use Surface\Toolkits\Menus\Shortcut;
use Surface\Toolkits\Menus\Standard;
use Surface\Toolkits\Menus\Toggle;
use Surface\Toolkits\Menus\Runtime\MenuNodeActivated;
use Surface\Toolkits\ToolkitSession;
use Surface\Contracts\Toolkits\Menus\BarNode;
use Surface\Contracts\Toolkits\Menus\BarNodeKind;
use Surface\Contracts\Toolkits\Menus\StandardKind;

/*
| The menu bar on real QMenuBars, QMenus and QActions, run with QT_QPA_PLATFORM=offscreen so
| nothing is on screen. What the offscreen platform has none of is not checked here: the macOS
| system bar Qt writes the actions into, and a real key press. Those are checked by running an app.
*/

const MAC = PHP_OS_FAMILY === 'Darwin';

/** @return array{MenuManager, QtAdapterDriver, QtSession} */
function quietMenus(?MenuBar $default = null): array
{
    static $toolkit = null;

    $windows = is_null($default) ? [] : ['default_menu' => 'app', 'menus' => ['app' => $default]];
    $app = bootCore(['toolkits' => ['windows' => $windows]]);
    $toolkit ??= (new Qt())->setQApplicationNames('Qt Menu Tests', APPLICATION_ID)->setTimerBudget();

    $session = new QtSession($app, 'Qt Menu Tests', APPLICATION_ID);
    Closure::bind(function () use ($toolkit): void {
        [$this->application, $this->initialized, $this->connected] = [$toolkit, true, true];
    }, $session, ToolkitSession::class)();

    $driver = new QtAdapterDriver($app);
    Closure::bind(function () use ($session): void {
        $this->session = $session;
    }, $driver, QtAdapterDriver::class)();

    return [$app->make(MenuManager::class), $driver, $session];
}

function quietWindow(array $menus, ?MenuBar $bar = null, string $name = 'main'): QtWindow
{
    [$manager, $driver] = $menus;
    /** @var QtWindow $window */
    $window = $driver->open($name, 400, 300);
    $manager->opened($window, $driver, $bar);
    activate($window);

    return $window;
}

/** What the window's event filter runs when Qt activates it. */
function activate(QtWindow $window): void
{
    (fn () => $this->filtered(EventType::WINDOW_ACTIVATE))->call($window);
}

function deactivate(QtWindow $window): void
{
    (fn () => $this->filtered(EventType::WINDOW_DEACTIVATE))->call($window);
}

function barOf(string $window = 'main'): QtMenuBar
{
    return (fn () => $this->windows[$window]->mounter->bar)->call(app(MenuManager::class));
}

function unfocusedBar(): QtMenuBar
{
    return (fn () => $this->unfocused->mounter->bar)->call(app(MenuManager::class));
}

/**
 * The node at a path of titles, as [uuid, native]. A choice's option is found by its label.
 *
 * @return array{string, QAction}
 */
function nodeAt(QtMenuBar $bar, string ...$titles): array
{
    return (function () use ($titles): array {
        [$level, $found] = [$this->top, null];
        foreach ($titles as $title) {
            $found = null;
            foreach ($level as $uuid) {
                foreach ($this->nodes[$uuid]->natives as $native) {
                    if (str_replace('&&', '&', $native->text()) === $title) {
                        $found = [$uuid, $native];
                        break 2;
                    }
                }
            }
            if (is_null($found)) {
                throw new RuntimeException("No native menu item '{$title}'.");
            }
            $level = $this->nodes[$found[0]]->children;
        }

        return $found;
    })->call($bar);
}

function nativeAt(QtMenuBar $bar, string ...$titles): QAction
{
    return nodeAt($bar, ...$titles)[1];
}

/**
 * The titles under a path, in the bar's order; '-' is a line.
 *
 * @return list<string>
 */
function titlesUnder(QtMenuBar $bar, string ...$titles): array
{
    $under = $titles === [] ? null : nodeAt($bar, ...$titles)[0];

    return (function () use ($under): array {
        $out = [];
        foreach (is_null($under) ? $this->top : $this->nodes[$under]->children as $uuid) {
            foreach ($this->nodes[$uuid]->natives as $native) {
                $out[] = $native->isSeparator() ? '-' : str_replace('&&', '&', $native->text());
            }
        }

        return $out;
    })->call($bar);
}

/** @return list<object> what was posted, each node chosen delivered as the loop would */
function delivered(array $menus): array
{
    [$manager, , $session] = $menus;
    $take = fn (): array => Closure::bind(function (): array {
        [$mail, $this->outbox] = [$this->outbox, []];

        return $mail;
    }, $session, ToolkitSession::class)();

    foreach ($take() as $mail) {
        if ($mail instanceof MenuNodeActivated) {
            $manager->deliver($mail);
        }
    }

    return $take();
}

function runDeletes(): void
{
    QCoreApplication::sendPostedEvents(null, EventType::DEFERRED_DELETE->value);
}

afterEach(function (): void {
    foreach ($this->opened ?? [] as $window) {
        if ($window->isOpen()) {
            $window->close();
        }
    }
    runDeletes();
});

it('builds the tree in the order written', function (): void {
    $menus = quietMenus();
    $this->opened[] = quietWindow($menus, MenuBar::make(
        Menu::make('File', 'file',
            Item::make('Open', id: 'open'),
            Separator::make(),
            Menu::make('Export', 'export', Item::make('PDF', id: 'pdf')),
            Choice::make(['s' => 'Small', 'l' => 'Large'], 'l', id: 'size'),
            Toggle::make('Wrap & Fold', true, id: 'wrap'),
        ),
        Menu::make('Help', 'help', Item::make('Guide', id: 'guide')),
    ));
    $bar = barOf();

    expect(array_slice(titlesUnder($bar), -2))->toBe(['File', 'Help'])
        ->and(titlesUnder($bar, 'File'))->toBe(['Open', '-', 'Export', 'Small', 'Large', 'Wrap & Fold'])
        ->and(titlesUnder($bar, 'File', 'Export'))->toBe(['PDF'])
        ->and(nativeAt($bar, 'File', 'Wrap & Fold')->text())->toBe('Wrap && Fold')    // no mnemonic
        ->and(nativeAt($bar, 'File', 'Small')->isChecked())->toBeFalse()
        ->and(nativeAt($bar, 'File', 'Large')->isChecked())->toBeTrue()
        ->and(nativeAt($bar, 'File', 'Wrap & Fold')->isChecked())->toBeTrue()
        ->and(nativeAt($bar, 'File', 'Open')->isCheckable())->toBeFalse()
        ->and($bar->bar())->toBe($this->opened[0]->native()->menuBar());
});

it('posts an item\'s mail, after its callback', function (): void {
    $menus = quietMenus();
    $ran = 0;
    $this->opened[] = quietWindow($menus, MenuBar::make(Menu::make('File', 'file',
        Item::make('Save', id: 'save', onClick: function () use (&$ran): void { $ran++; }),
    )));
    delivered($menus);

    nativeAt(barOf(), 'File', 'Save')->trigger();
    expect($ran)->toBe(0);    // never inside Qt's signal

    $mail = delivered($menus);
    expect($ran)->toBe(1)->and($mail[0]->name())->toBe('menu.clicked.file.save')->and($mail[0]->window)->toBe('main');
});

it('moves a toggle\'s and a choice\'s mark at once, by topside\'s word', function (): void {
    $menus = quietMenus();
    $this->opened[] = quietWindow($menus, MenuBar::make(Menu::make('View', 'view',
        Toggle::make('Wrap', false, id: 'wrap'),
        Choice::make(['1' => 'One', '2' => 'Two'], '1', id: 'zoom'),
    )));
    delivered($menus);
    $bar = barOf();

    nativeAt($bar, 'View', 'Wrap')->trigger();
    nativeAt($bar, 'View', 'Two')->trigger();

    expect(nativeAt($bar, 'View', 'Wrap')->isChecked())->toBeTrue()
        ->and(nativeAt($bar, 'View', 'One')->isChecked())->toBeFalse()
        ->and(nativeAt($bar, 'View', 'Two')->isChecked())->toBeTrue();

    $mail = delivered($menus);
    expect([$mail[0]->name(), $mail[0]->on, $mail[1]->name(), $mail[1]->option])
        ->toBe(['menu.toggled.view.wrap', true, 'menu.chosen.view.zoom', '2']);

    // Choosing the chosen option again: Qt unchecks it, and the mark goes back.
    nativeAt($bar, 'View', 'Two')->trigger();
    expect(nativeAt($bar, 'View', 'Two')->isChecked())->toBeTrue()->and(delivered($menus))->toBe([]);
});

it('asks to quit and quits nothing', function (): void {
    $menus = quietMenus();
    $this->opened[] = quietWindow($menus, MenuBar::make(Menu::application(Standard::quit())));
    delivered($menus);
    $bar = barOf();

    nativeAt($bar, titlesUnder($bar)[0], titlesUnder($bar, titlesUnder($bar)[0])[0])->trigger();

    expect(delivered($menus)[0]->name())->toBe('quit.requested');
});

it('gives the application menu\'s nodes the roles Qt fills the macOS application menu from', function (): void {
    $menus = quietMenus();
    $this->opened[] = quietWindow($menus, MenuBar::make(
        Menu::application(
            About::panel(name: 'My App', version: '1.0'),
            Separator::make(),
            Item::make('Check for Updates', id: 'updates'),
            Toggle::make('Beta Channel', false, id: 'beta'),
            Standard::preferences(id: 'preferences'),
            Standard::hide(),
            Standard::quit(),
        ),
        Menu::make('File', 'file', Item::make('About This File', id: 'about'), Standard::quit()->shortcut(Shortcut::primary('E'))),
    ));
    $bar = barOf();
    $app = titlesUnder($bar)[0];
    $roles = array_map(fn (string $title): MenuRole => nativeAt($bar, $app, $title)->menuRole(), array_values(array_filter(
        titlesUnder($bar, $app),
        fn (string $title): bool => $title !== '-',
    )));

    expect($roles)->toBe([
        MenuRole::ABOUT_ROLE, MenuRole::APPLICATION_SPECIFIC_ROLE, MenuRole::APPLICATION_SPECIFIC_ROLE,
        MenuRole::PREFERENCES_ROLE, MenuRole::QUIT_ROLE,    // Hide has no native: Qt puts its own there
    ])
        ->and(nativeAt($bar, 'File', 'About This File')->menuRole())->toBe(MenuRole::NO_ROLE)    // Qt guesses nothing from a label
        ->and(nativeAt($bar, 'File', titlesUnder($bar, 'File')[1])->menuRole())->toBe(MenuRole::NO_ROLE)    // a Quit stays where it was put
        ->and($bar->hidesQuit())->toBeFalse();
})->skip(! MAC, 'Qt has an application menu on macOS only.');

it('hides Qt\'s own Quit while the application menu has none, and gives way to one', function (): void {
    $menus = quietMenus();
    $with = fn (bool $quit): MenuBar => MenuBar::make(
        Menu::application(...($quit ? [Standard::quit()] : [])),
        Menu::make('File', 'file', Standard::quit()->shortcut(Shortcut::primary('E'))),
    );
    $this->opened[] = quietWindow($menus, $with(false));
    $bar = barOf();

    expect($bar->hidesQuit())->toBeTrue();

    $menus[0]->window('main', $with(true));
    expect($bar->hidesQuit())->toBeFalse();

    $menus[0]->window('main', $with(false));
    expect($bar->hidesQuit())->toBeTrue();
})->skip(! MAC, 'Qt has an application menu on macOS only.');

it('puts nothing in an application menu off macOS: it is the first menu', function (): void {
    [, , $session] = quietMenus();
    $bar = new QtMenuBar($session, null, false);
    $bar->insert(null, 0, new BarNode(BarNodeKind::MENU, 'app', 'My App', application: true));
    $bar->insert('app', 0, new BarNode(BarNodeKind::STANDARD, 'about', 'About', standard: StandardKind::ABOUT, replaced: true));
    $bar->insert('app', 1, new BarNode(BarNodeKind::STANDARD, 'quit', 'Quit', standard: StandardKind::QUIT));
    $bar->insert(null, 1, new BarNode(BarNodeKind::MENU, 'file', 'File'));

    expect(titlesUnder($bar))->toBe(['My App', 'File'])
        ->and(titlesUnder($bar, 'My App'))->toBe(['About', 'Quit'])
        ->and($bar->role('about'))->toBe(MenuRole::NO_ROLE)
        ->and($bar->role('quit'))->toBe(MenuRole::NO_ROLE)
        ->and($bar->natives('about')[0]->menuRole())->toBe(MenuRole::NO_ROLE)
        ->and($bar->hidesQuit())->toBeFalse();

    $bar->release();
});

it('edits a text widget with Qt\'s own slots, and nothing else', function (): void {
    quietMenus();
    $line = new QLineEdit('one two');
    $area = new QPlainTextEdit();
    $area->setPlainText('three');

    $line->selectAll();
    expect(QtMenuBar::edit($line, StandardKind::CUT))->toBeTrue()->and($line->text())->toBe('');
    expect(QtMenuBar::edit($line, StandardKind::PASTE))->toBeTrue()->and($line->text())->toBe('one two');
    expect(QtMenuBar::edit($line, StandardKind::UNDO))->toBeTrue()->and($line->text())->toBe('');
    expect(QtMenuBar::edit($line, StandardKind::REDO))->toBeTrue()->and($line->text())->toBe('one two');

    expect(QtMenuBar::edit($area, StandardKind::SELECT_ALL))->toBeTrue()
        ->and(QtMenuBar::edit($area, StandardKind::COPY))->toBeTrue();
    $line->selectAll();
    QtMenuBar::edit($line, StandardKind::PASTE);
    expect($line->text())->toBe('three');

    expect(QtMenuBar::edit(new QPushButton('No text'), StandardKind::COPY))->toBeFalse()
        ->and(QtMenuBar::edit(null, StandardKind::COPY))->toBeFalse()
        ->and(QtMenuBar::edit($line, StandardKind::QUIT))->toBeFalse();
});

it('lets a standard item given an id report instead', function (): void {
    $menus = quietMenus();
    $this->opened[] = quietWindow($menus, MenuBar::make(
        Menu::application(About::item(id: 'about')),
        Menu::make('Edit it', 'edit', Standard::copy()->id('copy')),
    ));
    delivered($menus);
    $bar = barOf();
    $app = titlesUnder($bar)[0];

    nativeAt($bar, $app, titlesUnder($bar, $app)[0])->trigger();
    nativeAt($bar, 'Edit it', 'Copy')->trigger();

    expect(array_map(fn (object $mail): string => $mail->name(), delivered($menus)))
        ->toBe(['menu.clicked.app.about', 'menu.clicked.edit.copy']);
});

it('closes its window from Close Window, by the window\'s own close path', function (): void {
    $menus = quietMenus();
    $this->opened[] = $window = quietWindow($menus, MenuBar::make(Menu::make('Window', 'window', Standard::closeWindow())));
    $close = nativeAt(barOf(), 'Window', titlesUnder(barOf(), 'Window')[0]);

    $close->trigger();

    expect($window->isOpen())->toBeFalse();
});

it('shows what ext-qt cannot do disabled', function (): void {
    $menus = quietMenus();
    $this->opened[] = quietWindow($menus, MenuBar::make(Menu::make('Window', 'window',
        Standard::minimize(), Standard::fullscreen(), Standard::closeWindow(),
    )));
    $bar = barOf();
    $enabled = array_map(fn (string $title): bool => nativeAt($bar, 'Window', $title)->isEnabled(), titlesUnder($bar, 'Window'));

    expect($enabled)->toBe([false, false, true])
        ->and($bar->supports(StandardKind::MINIMIZE))->toBeFalse()
        ->and($bar->supports(StandardKind::FULLSCREEN))->toBeFalse()
        ->and($bar->supports(StandardKind::COPY))->toBeTrue();
});

it('shows enabled and disabled, menus inside menus too', function (): void {
    $menus = quietMenus();
    $bar = fn (bool $on): MenuBar => MenuBar::make(
        Menu::make('File', 'file',
            Item::make('Save', id: 'save')->enabled($on),
            Menu::make('Export', 'export', Item::make('PDF', id: 'pdf'))->enabled($on),
            Standard::copy()->enabled($on),
        ),
        Menu::make('Tools', 'tools', Item::make('Run', id: 'run'))->enabled($on),
    );
    $this->opened[] = quietWindow($menus, $bar(false));
    $native = barOf();

    $state = fn (): array => [
        nativeAt($native, 'File', 'Save')->isEnabled(),
        nativeAt($native, 'File', 'Export')->isEnabled(),
        nativeAt($native, 'File', 'Export', 'PDF')->isEnabled(),
        nativeAt($native, 'File', 'Copy')->isEnabled(),
        nativeAt($native, 'Tools')->isEnabled(),
        nativeAt($native, 'Tools', 'Run')->isEnabled(),
    ];
    expect($state())->toBe([false, false, false, false, false, false]);

    $menus[0]->window('main', $bar(true));
    expect($state())->toBe([true, true, true, true, true, true]);

    $menus[0]->window('main', $bar(false));
    expect($state())->toBe([false, false, false, false, false, false]);
});

it('changes in place: label, order, what is added and removed, shortcut', function (): void {
    $menus = quietMenus();
    $this->opened[] = quietWindow($menus, MenuBar::make(Menu::make('List', 'list',
        Item::make('One', id: 'one'),
        Item::make('Two', id: 'two'),
        Choice::make(['a' => 'A', 'b' => 'B'], 'a', id: 'letter'),
        Item::make('Three', id: 'three'),
    )));
    $bar = barOf();
    $kept = nativeAt($bar, 'List', 'One');
    $gone = nativeAt($bar, 'List', 'Two');

    $menus[0]->window('main', MenuBar::make(Menu::make('List', 'list',
        Choice::make(['a' => 'A', 'b' => 'B'], 'a', id: 'letter'),
        Item::make('New', id: 'new'),
        Item::make('Three', id: 'three'),
        Item::make('First', id: 'one')->shortcut(Shortcut::primary('J')),
    )));
    runDeletes();

    expect(titlesUnder($bar, 'List'))->toBe(['A', 'B', 'New', 'Three', 'First'])
        ->and(nativeAt($bar, 'List', 'First'))->toBe($kept)
        ->and($kept->shortcut()->toString())->toBe('Ctrl+J')
        ->and(fn () => $gone->text())->toThrow(QtException::class);    // Qt deleted it
});

it('writes a shortcut in Qt\'s portable text', function (string $written, string $mac, string $elsewhere): void {
    $shortcut = Shortcut::of($written);

    expect(QtShortcut::portable($shortcut, true))->toBe($mac)
        ->and(QtShortcut::portable($shortcut, false))->toBe($elsewhere)
        ->and(QtShortcut::sequence($shortcut, MAC)->toString())->toBe(MAC ? $mac : $elsewhere)    // Qt reads it back the same
        ->and(QtShortcut::sequence(null, MAC)->isEmpty())->toBeTrue();
})->with([
    ['primary+s', 'Ctrl+S', 'Ctrl+S'],
    ['primary+shift+s', 'Ctrl+Shift+S', 'Ctrl+Shift+S'],
    ['ctrl+alt+up', 'Meta+Alt+Up', 'Ctrl+Alt+Up'],
    ['primary+ctrl+f', 'Meta+Ctrl+F', 'Ctrl+F'],
    ['primary+,', 'Ctrl+,', 'Ctrl+,'],
    ['alt+pagedown', 'Alt+PgDown', 'Alt+PgDown'],
    ['delete', 'Del', 'Del'],
    ['shift+f5', 'Shift+F5', 'Shift+F5'],
    ['primary+enter', 'Ctrl+Return', 'Ctrl+Return'],
    ['primary+escape', 'Ctrl+Esc', 'Ctrl+Esc'],
    ['primary+1', 'Ctrl+1', 'Ctrl+1'],
]);

it('gives a window its own QMenuBar and the parentless one to no window', function (): void {
    $menus = quietMenus(MenuBar::make(Menu::make('Default', 'default', Item::make('Open', id: 'open'))));
    $this->opened[] = $one = quietWindow($menus, MenuBar::make(Menu::make('One', 'one')), 'one');
    $this->opened[] = $two = quietWindow($menus, MenuBar::make(Menu::make('Two', 'two', Item::make('Go', id: 'go'))), 'two');
    delivered($menus);

    expect(barOf('one')->bar())->toBe($one->native()->menuBar())
        ->and(barOf('two')->bar())->toBe($two->native()->menuBar())
        ->and(array_slice(titlesUnder(barOf('one')), -1))->toBe(['One'])
        ->and(array_slice(titlesUnder(barOf('two')), -1))->toBe(['Two'])
        ->and(unfocusedBar()->bar()->parentWidget())->toBeNull()
        ->and(array_slice(titlesUnder(unfocusedBar()), -1))->toBe(['Default']);

    // A bar reports for its own window whichever is active, and the parentless one for none.
    deactivate($two);
    nativeAt(barOf('two'), 'Two', 'Go')->trigger();
    nativeAt(unfocusedBar(), 'Default', 'Open')->trigger();
    $mail = delivered($menus);

    expect(array_map(fn (object $m): array => [$m->name(), $m->window], $mail))
        ->toBe([['menu.clicked.two.go', 'two'], ['menu.clicked.default.open', null]]);
})->skip(! MAC, 'Topside keeps a bar for no window on macOS only.');

it('drops every native when its window closes', function (): void {
    $menus = quietMenus();
    $this->opened[] = $window = quietWindow($menus, MenuBar::make(Menu::make('File', 'file', Item::make('Save', id: 'save'))));
    $bar = barOf();
    $save = nativeAt($bar, 'File', 'Save');

    $window->close();
    runDeletes();

    expect(titlesUnder($bar))->toBe([])
        ->and(fn () => $save->text())->toThrow(QtException::class);
});

it('builds the About box from the fields given', function (): void {
    [, , $session] = quietMenus();

    $box = $session->showAbout(new AboutPanel('My App', '1.2', '2026 Me', 'Thanks'));
    expect([$box->windowTitle(), $box->text(), $box->informativeText()])->toBe(['About My App', 'My App 1.2', "2026 Me\n\nThanks"])
        ->and($session->aboutBox())->toBe($box);

    $again = $session->showAbout(new AboutPanel('My App'));
    expect($again)->toBe($box)
        ->and([$box->text(), $box->informativeText()])->toBe(['My App', '']);

    $box->close();
    runDeletes();
    expect($session->aboutBox())->toBeNull();
});

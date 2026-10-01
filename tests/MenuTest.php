<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Bridge\QtBridgeDriver;
use QAction\MenuRole;
use Surface\Contracts\Windows\Mail\MenuActivated;
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
        ->and($bar->action('app.quit')->shortcut())->toBe('Ctrl+Q')
        ->and($bar->action('view.refresh')->menuRole())->toBe(MenuRole::NO_ROLE)
        ->and($bar->action('view.refresh')->shortcut())->toBe('Ctrl+R')
        ->and($bar->action('grid')->isCheckable())->toBeTrue()
        ->and($bar->action('grid')->isChecked())->toBeFalse()
        ->and(fn () => $bar->action('nope'))->toThrow(WindowException::class, "No item 'nope'");
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

<?php

declare(strict_types=1);

use QContextMenuEvent\Reason;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\MenuRadioChosen;
use Surface\Contracts\Windows\Mail\MenuToggled;
use Surface\Contracts\Windows\WindowException;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

const CHOICE_MENU = [
    ['id' => 'wrap', 'label' => 'Wrap', 'toggle' => true],
    ['separator' => true],
    ['id' => 'case.none', 'label' => 'None', 'radio' => 'case', 'on' => true],
    ['id' => 'case.all', 'label' => 'All', 'radio' => 'case'],
];

/** The menu mail posted since the last read. */
function menuMail(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof MenuActivated || $mail instanceof MenuToggled || $mail instanceof MenuRadioChosen));
}

function shownLabel(): array
{
    $window = driver()->open('main', 300, 200);
    $label = $window->column('m')->label('l', 'Label')->minSize(120, 40);
    $label->setContextMenu(CHOICE_MENU);
    $at = canvasShownAt($window, $label->native(), 4.0, 5.0);
    takeMail(session());

    return [$window, $label, $at];
}

/** Right-click the label, as Qt raises the context menu event, and return the menu the driver opened. */
function rightClick($window, $label, array $at)
{
    QCoreApplication::sendEvent($label->native(), new QContextMenuEvent(Reason::MOUSE, new QPoint(4, 5), new QPoint((int) round($at[0]), (int) round($at[1]))));

    return $window->rightClicks()->openMenu();
}

it('posts a context toggle and keeps it for the next open and in contextMenu()', function (): void {
    [$window, $label, $at] = shownLabel();

    $menu = rightClick($window, $label, $at);
    $menu->action('wrap')->trigger();
    $menu->menu()->hide();
    $next = rightClick($window, $label, $at);

    expect(menuMail())->toEqual([new MenuToggled('main', 'wrap', true)])
        ->and($next->action('wrap')->isChecked())->toBeTrue()
        ->and($label->contextMenu()->items()['wrap']->isOn())->toBeTrue();
    $next->menu()->hide();
    $window->close();
});

it('posts a context radio choice once and keeps it', function (): void {
    [$window, $label, $at] = shownLabel();

    $menu = rightClick($window, $label, $at);
    $menu->action('case.all')->trigger();
    $menu->action('case.all')->trigger();
    $menu->menu()->hide();
    $next = rightClick($window, $label, $at);

    expect(menuMail())->toEqual([new MenuRadioChosen('main', 'case', 'case.all')])
        ->and([$next->action('case.none')->isChecked(), $next->action('case.all')->isChecked()])->toBe([false, true])
        ->and($label->contextMenu()->radios())->toBe(['case' => 'case.all']);
    $next->menu()->hide();
    $window->close();
});

it('opens a menu at a point from code at once, its choices posted and not kept', function (): void {
    [$window, $label] = shownLabel();

    $label->popupMenu(CHOICE_MENU, 10.0, 5.0);
    $open = $label->openPopup();
    $shown = $open->menu()->isVisible();
    $open->action('wrap')->trigger();

    expect($shown)->toBeTrue()
        ->and(menuMail())->toEqual([new MenuToggled('main', 'wrap', true)])
        ->and($label->contextMenu()->items()['wrap']->isOn())->toBeFalse();
    $open->menu()->close();
    $window->close();
});

it('refuses to open a menu in a window not shown', function (): void {
    $window = driver()->open('main', 300, 200);
    $label = $window->column('m')->label('l', 'Label');

    expect(fn () => $label->popupMenu(CHOICE_MENU, 0.0, 0.0))->toThrow(WindowException::class, "'m.l' cannot open a menu: it is not shown.");
    $window->close();
});

it('closes the popped menu when its primitive is removed, posting nothing', function (): void {
    [$window, $label] = shownLabel();
    $label->popupMenu(CHOICE_MENU, 10.0, 5.0);
    $menu = $label->openPopup()->menu();

    $label->remove();
    pumpFor(0.05);

    expect($menu->isVisible())->toBeFalse()
        ->and(menuMail())->toBe([]);
    $window->close();
});

<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Windows\QtWindow;
use QContextMenuEvent\Reason;
use QEvent\Type;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\View\RightClicked;
use Surface\Contracts\Windows\Mail\WindowRightClicked;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/**
 * A shown window's widget, and the global point of ($x, $y) in it. The checks hit-test the screen
 * (QApplication::widgetAt), so the window must be the active one, on top. macOS activation is
 * cooperative: while the user works in another app the system may decline it, and no API forces it,
 * so there the test is skipped with that reason. Elsewhere activation is required.
 */
function shownAt(QtWindow $window, QWidget $widget, float $x, float $y): array
{
    $window->present();
    for ($waited = 0; (! $widget->isVisible() || ! $window->native()->isActiveWindow()) && $waited < 60; $waited++) {
        pumpFor(0.05);
    }
    if (! $window->native()->isActiveWindow()) {
        if (PHP_OS_FAMILY === 'Darwin') {
            test()->markTestSkipped('macOS declined to activate the app: another app holds focus (cooperative activation).');
        }
        throw new RuntimeException("Window '{$window->name()}' did not become active.");
    }
    pumpFor(0.2);
    $origin = $widget->mapFromGlobal(new QPointF(0.0, 0.0));

    return [$x - $origin->x(), $y - $origin->y()];
}

/** @return list<object> the right-click mail posted since the last read */
function qtRightClickMail(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof RightClicked || $mail instanceof WindowRightClicked));
}

function contextMenu(QWidget $widget, int $x, int $y, int $gx, int $gy, Reason $reason = Reason::MOUSE): void
{
    QCoreApplication::sendEvent($widget, new QContextMenuEvent($reason, new QPoint($x, $y), new QPoint($gx, $gy)));
}

/** A primary-button mouse event of $type at the local and global points, from the primary pointing device. */
function qtMouse(Type $type, float $x, float $y, float $gx, float $gy, int $button, int $buttons): QMouseEvent
{
    return new QMouseEvent($type, new QPointF($x, $y), new QPointF($gx, $gy), $button, $buttons, 0, QPointingDevice::primaryPointingDevice());
}

it('posts view.right-clicked for the innermost primitive Qt raises a context menu on, in its own coordinates', function (): void {
    $window = driver()->open('main', 300, 200);
    $go = $window->column('m')->row('r')->button('go', 'Go');
    [$gx, $gy] = shownAt($window, $go->native(), 4.0, 5.0);
    takeMail(session());
    contextMenu($go->native(), 4, 5, (int) round($gx), (int) round($gy));

    $mail = qtRightClickMail();
    expect($mail)->toHaveCount(1)
        ->and($mail[0])->toBeInstanceOf(RightClicked::class)
        ->and([$mail[0]->window, $mail[0]->path, $mail[0]->uuid])->toBe(['main', 'm.r.go', $go->uuid()])
        ->and([round($mail[0]->x), round($mail[0]->y)])->toBe([4.0, 5.0]);
});

it('posts window.right-clicked on content with no primitive, and nothing for the keyboard menu key', function (): void {
    $window = driver()->open('bare', 300, 200);
    [$gx, $gy] = shownAt($window, $window->contentArea(), 30.0, 40.0);
    takeMail(session());
    contextMenu($window->contentArea(), 30, 40, (int) round($gx), (int) round($gy), Reason::KEYBOARD);
    $keyboard = qtRightClickMail();
    contextMenu($window->contentArea(), 30, 40, (int) round($gx), (int) round($gy));

    $mail = qtRightClickMail();
    expect($keyboard)->toBe([])
        ->and($mail)->toHaveCount(1)
        ->and($mail[0])->toBeInstanceOf(WindowRightClicked::class)
        ->and([$mail[0]->window, round($mail[0]->x), round($mail[0]->y)])->toBe(['bare', 30.0, 40.0]);
});

it('posts nothing for a disabled primitive or one inside a disabled group', function (): void {
    $window = driver()->open('main', 300, 200);
    $row = $window->column('m')->row('r');
    $go = $row->button('go', 'Go');
    [$gx, $gy] = shownAt($window, $go->native(), 4.0, 5.0);
    takeMail(session());
    $go->setEnabled(false);
    contextMenu($go->native(), 4, 5, (int) round($gx), (int) round($gy));
    $own = qtRightClickMail();
    $go->setEnabled(true);
    $row->setEnabled(false);
    contextMenu($go->native(), 4, 5, (int) round($gx), (int) round($gy));

    expect([$own, qtRightClickMail()])->toBe([[], []]);
});

it('posts for a primary press held still past the hold interval, not for one let go early or dragged', function (): void {
    $window = driver()->open('main', 300, 200);
    $window->rightClicks()->holdOnPress(true);
    $go = $window->column('m')->button('go', 'Go');
    [$gx, $gy] = shownAt($window, $go->native(), 4.0, 5.0);
    $hold = QGuiApplication::styleHints()->mousePressAndHoldInterval() / 1000;
    $drag = QGuiApplication::styleHints()->startDragDistance();
    $press = fn (Type $type, float $dx = 0.0) => QCoreApplication::sendEvent($go->native(), qtMouse($type, 4.0 + $dx, 5.0, $gx + $dx, $gy, $type === Type::MOUSE_BUTTON_RELEASE ? 1 : ($type === Type::MOUSE_MOVE ? 0 : 1), $type === Type::MOUSE_BUTTON_RELEASE ? 0 : 1));
    takeMail(session());

    // Let go before the hold time: released at once, as a wait for half of it can overrun on a loaded machine.
    $press(Type::MOUSE_BUTTON_PRESS);
    $press(Type::MOUSE_BUTTON_RELEASE);
    pumpFor($hold * 1.5);
    $early = qtRightClickMail();

    $press(Type::MOUSE_BUTTON_PRESS);
    $press(Type::MOUSE_MOVE, $drag + 2.0);
    pumpFor($hold * 1.5);
    $dragged = qtRightClickMail();
    $press(Type::MOUSE_BUTTON_RELEASE, $drag + 2.0);

    // The timer can fire late on macOS when the process is not frontmost: wait for the mail, up to four holds.
    $press(Type::MOUSE_BUTTON_PRESS);
    $held = [];
    for ($until = microtime(true) + $hold * 4; $held === [] && microtime(true) < $until;) {
        pumpFor(0.05);
        $held = array_map(fn (object $mail): string => $mail->name(), qtRightClickMail());
    }
    $press(Type::MOUSE_BUTTON_RELEASE);

    expect([$early, $dragged, $held])->toBe([[], [], ['view.right-clicked.main.m.go']]);
});

it('stops watching the application once its window closes', function (): void {
    $window = driver()->open('gone', 300, 200);
    $clicks = $window->rightClicks();
    $window->close();
    pumpFor(0.1);

    expect($clicks->watching())->toBeFalse();
});

/** @return list<string> the names of the right-click and menu mail posted since the last read */
function qtContextMail(): array
{
    return array_values(array_map(fn (object $mail): string => $mail->name(), array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof RightClicked || $mail instanceof WindowRightClicked || $mail instanceof MenuActivated)));
}

it('opens a primitive\'s context menu at the click, after its mail, keeps the widget\'s own shut, and posts menu.activated', function (): void {
    $window = driver()->open('main', 300, 200);
    $name = $window->column('m')->textInput('name')->setContextMenu([
        ['label' => 'Download'],
        ['separator' => true],
        ['label' => 'Share', 'items' => [['label' => 'Copy Link']]],
    ]);
    [$gx, $gy] = shownAt($window, $name->native(), 4.0, 5.0);
    takeMail(session());

    $swallowed = QCoreApplication::sendEvent($name->native(), new QContextMenuEvent(Reason::MOUSE, new QPoint(4, 5), new QPoint((int) round($gx), (int) round($gy))));
    $menu = $window->rightClicks()->openMenu();
    $shown = $menu->menu()->isVisible();
    $clicked = qtContextMail();
    $menu->action('share.copy-link')->trigger();
    $menu->menu()->hide();

    expect($swallowed)->toBeTrue()
        ->and($shown)->toBeTrue()
        ->and($clicked)->toBe(['view.right-clicked.main.m.name'])
        ->and(qtContextMail())->toBe(['menu.activated.main.share.copy-link']);
});

it('opens the nearest group\'s menu when the primitive has none, and nothing for a disabled one', function (): void {
    $window = driver()->open('main', 300, 200);
    $outer = $window->column('m');
    $column = $outer->column('c')->setContextMenu([['label' => 'Refresh']]);
    $go = $column->button('go', 'Go');
    [$gx, $gy] = shownAt($window, $go->native(), 4.0, 5.0);
    contextMenu($go->native(), 4, 5, (int) round($gx), (int) round($gy));
    $menu = $window->rightClicks()->openMenu();
    $menu->action('refresh')->trigger();
    $menu->menu()->hide();
    $refreshed = qtContextMail();
    $column->disable();
    contextMenu($go->native(), 4, 5, (int) round($gx), (int) round($gy));

    expect($refreshed)->toBe(['view.right-clicked.main.m.c.go', 'menu.activated.main.refresh'])
        ->and($window->rightClicks()->openMenu())->toBe($menu)
        ->and(qtContextMail())->toBe([]);
});

it('opens the menu for a primary press held still', function (): void {
    $window = driver()->open('main', 300, 200);
    $go = $window->column('m')->button('go', 'Go')->setContextMenu([['label' => 'Download']]);
    [$gx, $gy] = shownAt($window, $go->native(), 4.0, 5.0);
    $window->rightClicks()->holdOnPress(true);
    takeMail(session());
    QCoreApplication::sendEvent($go->native(), qtMouse(Type::MOUSE_BUTTON_PRESS, 4.0, 5.0, $gx, $gy, 1, 1));
    $opened = false;
    for ($until = microtime(true) + QGuiApplication::styleHints()->mousePressAndHoldInterval() / 1000 * 4; ! $opened && microtime(true) < $until;) {
        pumpFor(0.05);
        $opened = $window->rightClicks()->openMenu()?->menu()->isVisible() ?? false;
    }
    QCoreApplication::sendEvent($go->native(), qtMouse(Type::MOUSE_BUTTON_RELEASE, 4.0, 5.0, $gx, $gy, 1, 0));
    $window->rightClicks()->openMenu()?->menu()->hide();

    expect($opened)->toBeTrue()
        ->and(qtContextMail())->toBe(['view.right-clicked.main.m.go']);
});

it('closes an open context menu with its window', function (): void {
    $window = driver()->open('main', 300, 200);
    $go = $window->column('m')->button('go', 'Go')->setContextMenu([['label' => 'Download']]);
    [$gx, $gy] = shownAt($window, $go->native(), 4.0, 5.0);
    contextMenu($go->native(), 4, 5, (int) round($gx), (int) round($gy));
    $menu = $window->rightClicks()->openMenu();
    $shown = $menu->menu()->isVisible();
    $window->close();
    pumpFor(0.1);

    expect($shown)->toBeTrue()
        ->and($menu->menu()->isVisible())->toBeFalse();
});

it('builds a context menu folder with enabled false disabled, and the items below it', function (): void {
    $window = driver()->open('main', 300, 200);
    $go = $window->column('m')->button('go', 'Go')->setContextMenu([
        ['label' => 'Share', 'enabled' => false, 'items' => [['label' => 'Copy Link']]],
        ['label' => 'Download'],
    ]);
    $menu = new Jovian\Toolkits\Qt\Windows\QtContextMenu($go->contextMenu(), 'main', session());

    expect($menu->action('share')->isEnabled())->toBeFalse()
        ->and($menu->action('share.copy-link')->isEnabled())->toBeFalse()
        ->and($menu->action('download')->isEnabled())->toBeTrue();
    $menu->menu()->deleteLater();
});

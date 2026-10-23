<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Windows\QtCanvasPointer;
use Jovian\Toolkits\Qt\Windows\QtWindow;
use QContextMenuEvent\Reason;
use QEvent\Type;
use Qt\MouseEventSource;
use Qt\ScrollPhase;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\CanvasClicked;
use Surface\Contracts\Windows\Mail\View\CanvasDragged;
use Surface\Contracts\Windows\Mail\View\CanvasPressed;
use Surface\Contracts\Windows\Mail\View\CanvasReleased;
use Surface\Contracts\Windows\Mail\View\CanvasWheeled;
use Surface\Contracts\Windows\Mail\View\RightClicked;
use Surface\Windows\Primitives\TKCanvas;
use Surface\Windows\Primitives\TKPrimitiveGroup;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/**
 * A window with a 200 × 120 canvas 'board' in column 'm', watching unless told not to.
 *
 * @return array{QtWindow, TKPrimitiveGroup, TKCanvas}
 */
function qtPointerWindow(bool $watch = true): array
{
    $window = driver()->open('main', 300, 240);
    $column = $window->column('m');
    $board = $column->canvas('board')->minSize(200, 120);
    if ($watch) {
        $board->watchPointer();
    }

    return [$window, $column, $board];
}

/** @return list<object> the pointer, click and right-click mail posted since the last read */
function qtPointerMail(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof CanvasPressed
        || $mail instanceof CanvasDragged || $mail instanceof CanvasReleased || $mail instanceof CanvasWheeled
        || $mail instanceof CanvasClicked || $mail instanceof RightClicked));
}

/** @return list<string> each mail's class short name, button where it has one */
function qtPointerKinds(array $mail): array
{
    return array_map(fn (object $m): string => (new ReflectionClass($m))->getShortName().(property_exists($m, 'button') ? ':'.$m->button->value : ''), $mail);
}

/** The widget under a global point: where Qt delivers a press, a double click or a wheel first. */
function qtUnder(float $gx, float $gy): QWidget
{
    return QApplication::widgetAt((int) round($gx), (int) round($gy));
}

/** Send a mouse event to $to at a global point, as Qt delivers one (it passes an ignored one on to parents). */
function qtSend(QObject $to, Type $type, float $gx, float $gy, int $button, int $buttons, int $modifiers = 0, int $timestamp = 0): void
{
    $local = $to instanceof QWidget ? $to->mapFromGlobal(new QPointF($gx, $gy)) : new QPointF(0.0, 0.0);
    $event = new QMouseEvent($type, $local, new QPointF($gx, $gy), $button, $buttons, $modifiers, QPointingDevice::primaryPointingDevice());
    $event->setTimestamp($timestamp);
    QCoreApplication::sendEvent($to, $event);
}

/** Send a wheel event to the widget under a global point. */
function qtWheel(float $gx, float $gy, QPoint $pixel, QPoint $angle, MouseEventSource $source = MouseEventSource::MOUSE_EVENT_NOT_SYNTHESIZED, int $modifiers = 0): void
{
    $to = qtUnder($gx, $gy);
    $event = new QWheelEvent($to->mapFromGlobal(new QPointF($gx, $gy)), new QPointF($gx, $gy), $pixel, $angle, 0, $modifiers,
        ScrollPhase::NO_SCROLL_PHASE, false, $source, QPointingDevice::primaryPointingDevice());
    QCoreApplication::sendEvent($to, $event);
}

it('posts no pointer mail from a canvas that is not watching, and its click as before', function (): void {
    [$window, , $board] = qtPointerWindow(watch: false);
    [$gx, $gy] = canvasShownAt($window, $board->native(), 12.0, 34.0);
    takeMail(session());
    $leaf = qtUnder($gx, $gy);
    qtSend($leaf, Type::MOUSE_BUTTON_PRESS, $gx, $gy, 1, 1);
    qtSend($leaf, Type::MOUSE_MOVE, $gx + 1.0, $gy, 0, 1);
    qtSend($leaf, Type::MOUSE_BUTTON_RELEASE, $gx + 1.0, $gy, 1, 0);

    expect(qtPointerKinds(qtPointerMail()))->toBe(['CanvasClicked']);
});

it('posts the press, each move that changed the point, and the release once each, in canvas coordinates', function (): void {
    [$window, , $board] = qtPointerWindow();
    [$gx, $gy] = canvasShownAt($window, $board->native(), 12.0, 34.0);
    takeMail(session());
    $leaf = qtUnder($gx, $gy);
    qtSend($leaf, Type::MOUSE_BUTTON_PRESS, $gx, $gy, 1, 1);
    // Past Qt's drag distance, so no click posts with the release.
    qtSend($leaf, Type::MOUSE_MOVE, $gx + 30.0, $gy, 0, 1);
    qtSend($leaf, Type::MOUSE_MOVE, $gx + 30.0, $gy, 0, 1);
    qtSend($leaf, Type::MOUSE_BUTTON_RELEASE, $gx + 30.0, $gy, 1, 0);

    $mail = qtPointerMail();
    expect(qtPointerKinds($mail))->toBe(['CanvasPressed:left', 'CanvasDragged:left', 'CanvasReleased:left'])
        ->and(array_map(fn (object $m): string => $m->name(), $mail))->toBe(['view.pressed.main.m.board', 'view.dragged.main.m.board', 'view.released.main.m.board'])
        ->and(array_map(fn (object $m): array => [round($m->x), round($m->y)], $mail))->toBe([[12.0, 34.0], [42.0, 34.0], [42.0, 34.0]])
        ->and([$mail[0]->uuid, $mail[0]->clicks])->toBe([$board->uuid(), 1])
        ->and($mail[0]->modifiers)->toEqual(new Modifiers());
});

it('keeps posting the drag and the release Qt sends the press\'s receiver from outside the canvas and the window', function (): void {
    [$window, , $board] = qtPointerWindow();
    [$gx, $gy] = canvasShownAt($window, $board->native(), 20.0, 20.0);
    takeMail(session());
    $leaf = qtUnder($gx, $gy);
    qtSend($leaf, Type::MOUSE_BUTTON_PRESS, $gx, $gy, 1, 1);
    qtSend($leaf, Type::MOUSE_MOVE, $gx - 400.0, $gy + 500.0, 0, 1);
    qtSend($leaf, Type::MOUSE_BUTTON_RELEASE, $gx - 400.0, $gy + 500.0, 1, 0);

    $mail = qtPointerMail();
    expect(qtPointerKinds($mail))->toBe(['CanvasPressed:left', 'CanvasDragged:left', 'CanvasReleased:left'])
        ->and($mail[1]->x)->toBeLessThan(0.0)
        ->and($mail[1]->y)->toBeGreaterThan(120.0);
});

it('posts nothing for a second button pressed and released during a drag', function (): void {
    [$window, , $board] = qtPointerWindow();
    [$gx, $gy] = canvasShownAt($window, $board->native(), 20.0, 20.0);
    takeMail(session());
    $leaf = qtUnder($gx, $gy);
    qtSend($leaf, Type::MOUSE_BUTTON_PRESS, $gx, $gy, 1, 1);
    qtSend($leaf, Type::MOUSE_BUTTON_PRESS, $gx, $gy, 2, 3);
    qtSend($leaf, Type::MOUSE_MOVE, $gx + 10.0, $gy, 0, 3);
    qtSend($leaf, Type::MOUSE_BUTTON_RELEASE, $gx + 10.0, $gy, 2, 1);
    qtSend($leaf, Type::MOUSE_BUTTON_RELEASE, $gx + 10.0, $gy, 1, 0);

    expect(qtPointerKinds(qtPointerMail()))->toBe(['CanvasPressed:left', 'CanvasDragged:left', 'CanvasReleased:left']);
});

it('posts a right press, its drag and its release on a canvas with no context menu', function (): void {
    [$window, , $board] = qtPointerWindow();
    [$gx, $gy] = canvasShownAt($window, $board->native(), 20.0, 20.0);
    takeMail(session());
    $leaf = qtUnder($gx, $gy);
    qtSend($leaf, Type::MOUSE_BUTTON_PRESS, $gx, $gy, 2, 2);
    qtSend($leaf, Type::MOUSE_MOVE, $gx + 5.0, $gy + 2.0, 0, 2);
    qtSend($leaf, Type::MOUSE_BUTTON_RELEASE, $gx + 5.0, $gy + 2.0, 2, 0);

    expect(qtPointerKinds(qtPointerMail()))->toBe(['CanvasPressed:right', 'CanvasDragged:right', 'CanvasReleased:right']);
});

it('counts presses of the same button within Qt\'s double-click interval and distance, double-click events included', function (): void {
    [$window, , $board] = qtPointerWindow();
    [$gx, $gy] = canvasShownAt($window, $board->native(), 20.0, 20.0);
    takeMail(session());
    $leaf = qtUnder($gx, $gy);
    $interval = QGuiApplication::styleHints()->mouseDoubleClickInterval();
    // Qt's own order: the second press, then the double click it raises at the same time.
    qtSend($leaf, Type::MOUSE_BUTTON_PRESS, $gx, $gy, 1, 1, timestamp: 1000);
    qtSend($leaf, Type::MOUSE_BUTTON_RELEASE, $gx, $gy, 1, 0, timestamp: 1050);
    qtSend($leaf, Type::MOUSE_BUTTON_PRESS, $gx, $gy, 1, 1, timestamp: 1100);
    qtSend($leaf, Type::MOUSE_BUTTON_DBL_CLICK, $gx, $gy, 1, 1, timestamp: 1100);
    qtSend($leaf, Type::MOUSE_BUTTON_RELEASE, $gx, $gy, 1, 0, timestamp: 1150);
    qtSend($leaf, Type::MOUSE_BUTTON_PRESS, $gx, $gy, 1, 1, timestamp: 1200);
    qtSend($leaf, Type::MOUSE_BUTTON_RELEASE, $gx, $gy, 1, 0, timestamp: 1250);
    // A double click with no press before it is the press; one after the interval starts again.
    qtSend($leaf, Type::MOUSE_BUTTON_DBL_CLICK, $gx, $gy, 1, 1, timestamp: 1300);
    qtSend($leaf, Type::MOUSE_BUTTON_RELEASE, $gx, $gy, 1, 0, timestamp: 1350);
    qtSend($leaf, Type::MOUSE_BUTTON_PRESS, $gx, $gy, 1, 1, timestamp: 1350 + $interval + 1);
    qtSend($leaf, Type::MOUSE_BUTTON_RELEASE, $gx, $gy, 1, 0, timestamp: 1350 + $interval + 2);

    $presses = array_values(array_filter(qtPointerMail(), fn (object $m): bool => $m instanceof CanvasPressed));
    expect(array_map(fn (CanvasPressed $m): int => $m->clicks, $presses))->toBe([1, 2, 3, 4, 1]);
});

it('starts a new count further away than the double-click distance, or with another button', function (): void {
    [$window, , $board] = qtPointerWindow();
    [$gx, $gy] = canvasShownAt($window, $board->native(), 20.0, 20.0);
    takeMail(session());
    $leaf = qtUnder($gx, $gy);
    $far = QGuiApplication::styleHints()->mouseDoubleClickDistance() + 2.0;
    qtSend($leaf, Type::MOUSE_BUTTON_PRESS, $gx, $gy, 1, 1, timestamp: 1000);
    qtSend($leaf, Type::MOUSE_BUTTON_RELEASE, $gx, $gy, 1, 0, timestamp: 1010);
    qtSend($leaf, Type::MOUSE_BUTTON_PRESS, $gx + $far, $gy, 1, 1, timestamp: 1020);
    qtSend($leaf, Type::MOUSE_BUTTON_RELEASE, $gx + $far, $gy, 1, 0, timestamp: 1030);
    qtSend($leaf, Type::MOUSE_BUTTON_PRESS, $gx + $far, $gy, 2, 2, timestamp: 1040);
    qtSend($leaf, Type::MOUSE_BUTTON_RELEASE, $gx + $far, $gy, 2, 0, timestamp: 1050);

    $presses = array_values(array_filter(qtPointerMail(), fn (object $m): bool => $m instanceof CanvasPressed));
    expect(array_map(fn (CanvasPressed $m): int => $m->clicks, $presses))->toBe([1, 1, 1]);
});

it('reads Qt\'s buttons and modifiers, with Control and Meta swapped on macOS', function (): void {
    expect([QtCanvasPointer::button(1), QtCanvasPointer::button(2), QtCanvasPointer::button(4), QtCanvasPointer::button(8), QtCanvasPointer::button(16), QtCanvasPointer::button(32)])
        ->toBe([MouseButton::LEFT, MouseButton::RIGHT, MouseButton::MIDDLE, MouseButton::X1, MouseButton::X2, null])
        ->and(QtCanvasPointer::modifiers(0x02000000 | 0x04000000, macos: false))->toEqual(new Modifiers(shift: true, ctrl: true))
        ->and(QtCanvasPointer::modifiers(0x08000000 | 0x10000000, macos: false))->toEqual(new Modifiers(alt: true, meta: true))
        ->and(QtCanvasPointer::modifiers(0x04000000, macos: true))->toEqual(new Modifiers(meta: true))
        ->and(QtCanvasPointer::modifiers(0x10000000, macos: true))->toEqual(new Modifiers(ctrl: true));
});

it('posts the wheel over a watching canvas in notches, negated', function (): void {
    [$window, , $board] = qtPointerWindow();
    [$gx, $gy] = canvasShownAt($window, $board->native(), 20.0, 20.0);
    takeMail(session());
    qtWheel($gx, $gy, new QPoint(0, 0), new QPoint(60, -120), modifiers: 0x02000000);

    $mail = qtPointerMail();
    expect(qtPointerKinds($mail))->toBe(['CanvasWheeled'])
        ->and([$mail[0]->name(), $mail[0]->dx, $mail[0]->dy, $mail[0]->precise])->toBe(['view.wheeled.main.m.board', -0.5, 1.0, false])
        ->and($mail[0]->modifiers)->toEqual(new Modifiers(shift: true));
});

it('reads a wheel as precise by its source on macOS, by its pixel delta on Wayland, and never on X11', function (): void {
    $wheel = fn (QPoint $pixel, MouseEventSource $source) => new QWheelEvent(new QPointF(0.0, 0.0), new QPointF(0.0, 0.0), $pixel, new QPoint(0, -120), 0, 0,
        ScrollPhase::NO_SCROLL_PHASE, false, $source, QPointingDevice::primaryPointingDevice());
    $trackpad = $wheel(new QPoint(0, -3), MouseEventSource::MOUSE_EVENT_SYNTHESIZED_BY_SYSTEM);
    // macOS fills pixelDelta for a mouse wheel (scrollingDelta × 20); X11 drivers whose increment
    // is over 15 fill it with the raw valuator, 120 a notch (seen on the gaming PC's mouse).
    $macWheel = $wheel(new QPoint(0, -20), MouseEventSource::MOUSE_EVENT_NOT_SYNTHESIZED);
    $x11Wheel = $wheel(new QPoint(0, -120), MouseEventSource::MOUSE_EVENT_NOT_SYNTHESIZED);
    $notch = $wheel(new QPoint(0, 0), MouseEventSource::MOUSE_EVENT_NOT_SYNTHESIZED);

    expect([QtCanvasPointer::precise($trackpad, 'cocoa'), QtCanvasPointer::precise($macWheel, 'cocoa')])->toBe([true, false])
        ->and([QtCanvasPointer::precise($trackpad, 'wayland'), QtCanvasPointer::precise($notch, 'wayland')])->toBe([true, false])
        ->and([QtCanvasPointer::precise($x11Wheel, 'xcb'), QtCanvasPointer::precise($trackpad, 'xcb')])->toBe([false, false]);
});

it('eats the wheel over a watching canvas before the widget sees it, so a scroll view around it never scrolls; not watching, it passes', function (): void {
    $window = driver()->open('main', 300, 240);
    $content = $window->column('m')->scrollView('s')->fill()->column('c');
    $board = $content->canvas('board')->minSize(200, 120)->watchPointer();
    $content->label('tall', str_repeat("line\n", 80));
    [$gx, $gy] = canvasShownAt($window, $board->native(), 20.0, 20.0);
    takeMail(session());
    $leaf = qtUnder($gx, $gy);
    // A widget's own filters run after the application's: this one sees what reaches the widget.
    $reached = 0;
    $seen = new QEventFilter(function (QObject $watched, QEvent $event) use (&$reached): bool {
        $reached += $event->type() === Type::WHEEL ? 1 : 0;

        return false;
    });
    $leaf->installEventFilter($seen);
    $wheel = fn (): QWheelEvent => new QWheelEvent($leaf->mapFromGlobal(new QPointF($gx, $gy)), new QPointF($gx, $gy), new QPoint(0, 0), new QPoint(0, -120), 0, 0,
        ScrollPhase::NO_SCROLL_PHASE, false, MouseEventSource::MOUSE_EVENT_NOT_SYNTHESIZED, QPointingDevice::primaryPointingDevice());
    // Qt 6 passes a sent wheel to no parent, so what the window would do is read off the event: one
    // still accepted after delivery stops there; one the widget ignored goes up to the scroll view.
    $eaten = $wheel();
    QCoreApplication::sendEvent($leaf, $eaten);
    $watched = [$reached, $eaten->isAccepted(), qtPointerKinds(qtPointerMail())];
    $board->watchPointer(false);
    $passed = $wheel();
    QCoreApplication::sendEvent($leaf, $passed);
    $leaf->removeEventFilter($seen);

    expect($watched)->toBe([0, true, ['CanvasWheeled']])
        ->and([$reached, $passed->isAccepted()])->toBe([1, false])
        ->and(qtPointerMail())->toBe([]);
});

it('releases the press where it landed when a context menu opens for it, and nothing after', function (): void {
    [$window, , $board] = qtPointerWindow();
    $board->setContextMenu([['label' => 'Mark']]);
    [$gx, $gy] = canvasShownAt($window, $board->native(), 24.0, 36.0);
    takeMail(session());
    $leaf = qtUnder($gx, $gy);
    qtSend($leaf, Type::MOUSE_BUTTON_PRESS, $gx, $gy, 2, 2);
    $local = $leaf->mapFromGlobal(new QPointF($gx, $gy));
    QCoreApplication::sendEvent($leaf, new QContextMenuEvent(Reason::MOUSE, new QPoint((int) round($local->x()), (int) round($local->y())), new QPoint((int) round($gx), (int) round($gy))));
    $during = qtPointerMail();
    $window->rightClicks()->openMenu()?->menu()->hide();
    qtSend($leaf, Type::MOUSE_BUTTON_RELEASE, $gx, $gy, 2, 0);

    expect(qtPointerKinds($during))->toBe(['CanvasPressed:right', 'RightClicked', 'CanvasReleased:right'])
        ->and([round($during[2]->x), round($during[2]->y)])->toBe([24.0, 36.0])
        ->and(qtPointerMail())->toBe([]);
});

it('posts nothing when a press is interrupted with none owned', function (): void {
    [$window] = qtPointerWindow();
    takeMail(session());
    $window->canvasPointer()->interrupt();

    expect(qtPointerMail())->toBe([]);
});

it('drops the press silently when code ends it, and starts fresh on the next press', function (Closure $end, Closure $restore): void {
    [$window, $column, $board] = qtPointerWindow();
    [$gx, $gy] = canvasShownAt($window, $board->native(), 20.0, 20.0);
    takeMail(session());
    $leaf = qtUnder($gx, $gy);
    qtSend($leaf, Type::MOUSE_BUTTON_PRESS, $gx, $gy, 1, 1);
    $end($board, $column);
    qtSend($leaf, Type::MOUSE_MOVE, $gx + 30.0, $gy, 0, 1);
    qtSend($leaf, Type::MOUSE_BUTTON_RELEASE, $gx + 30.0, $gy, 1, 0);
    $after = qtPointerKinds(qtPointerMail());
    $restore($board, $column);
    qtSend($leaf, Type::MOUSE_BUTTON_PRESS, $gx, $gy, 2, 2);

    expect($after)->toBe(['CanvasPressed:left'])
        ->and(qtPointerKinds(qtPointerMail()))->toBe(['CanvasPressed:right']);
})->with([
    'stops watching' => [fn (TKCanvas $b) => $b->watchPointer(false), fn (TKCanvas $b) => $b->watchPointer()],
    'canvas disabled' => [fn (TKCanvas $b) => $b->setEnabled(false), fn (TKCanvas $b) => $b->setEnabled(true)],
    'group disabled' => [fn (TKCanvas $b, TKPrimitiveGroup $c) => $c->setEnabled(false), fn (TKCanvas $b, TKPrimitiveGroup $c) => $c->setEnabled(true)],
]);

it('drops the press silently when its canvas is removed', function (): void {
    [$window, , $board] = qtPointerWindow();
    [$gx, $gy] = canvasShownAt($window, $board->native(), 20.0, 20.0);
    takeMail(session());
    $leaf = qtUnder($gx, $gy);
    qtSend($leaf, Type::MOUSE_BUTTON_PRESS, $gx, $gy, 1, 1);
    $board->remove();
    qtSend($leaf, Type::MOUSE_MOVE, $gx + 10.0, $gy, 0, 1);
    qtSend($leaf, Type::MOUSE_BUTTON_RELEASE, $gx + 10.0, $gy, 1, 0);

    expect(qtPointerKinds(qtPointerMail()))->toBe(['CanvasPressed:left']);
});

it('posts the pointer, the click and the right click for a canvas lent to Metal, whose events reach its QWindow', function (): void {
    if (PHP_OS_FAMILY !== 'Darwin' || ! class_exists(NSView::class) || ! class_exists(CAMetalLayer::class)) {
        $this->markTestSkipped('Metal lending is macOS with ext-appkit and ext-metal');
    }
    [$window, , $board] = qtPointerWindow();
    [$gx, $gy] = canvasShownAt($window, $board->native(), 20.0, 20.0);
    $board->lend(SurfaceKind::METAL_LAYER, new QtLayerBorrower);
    pumpFor(0.2);
    $lent = $board->lentWindow();
    takeMail(session());
    qtSend($lent, Type::MOUSE_BUTTON_PRESS, $gx, $gy, 1, 1);
    qtSend($lent, Type::MOUSE_MOVE, $gx + 30.0, $gy, 0, 1);
    qtSend($lent, Type::MOUSE_BUTTON_RELEASE, $gx + 30.0, $gy, 1, 0);
    $dragged = qtPointerMail();
    $board->watchPointer(false);
    qtSend($lent, Type::MOUSE_BUTTON_PRESS, $gx, $gy, 1, 1);
    qtSend($lent, Type::MOUSE_BUTTON_RELEASE, $gx, $gy, 1, 0);
    $clicked = qtPointerKinds(qtPointerMail());
    qtSend($lent, Type::MOUSE_BUTTON_PRESS, $gx, $gy, 2, 2);
    qtSend($lent, Type::MOUSE_BUTTON_RELEASE, $gx, $gy, 2, 0);
    $right = qtPointerMail();
    // Before Qt 6.9 a QWindow gets no context-menu request: the right press is it, and this Qt's own
    // request for the same press must not post a second.
    $window->rightClicks()->lentPressOpensMenu(true);
    qtSend($lent, Type::MOUSE_BUTTON_PRESS, $gx, $gy, 2, 2);
    qtSend($lent, Type::MOUSE_BUTTON_RELEASE, $gx, $gy, 2, 0);
    $older = qtPointerKinds(qtPointerMail());
    $board->reclaim();

    expect($lent)->toBeInstanceOf(QWindow::class)
        ->and(qtPointerKinds($dragged))->toBe(['CanvasPressed:left', 'CanvasDragged:left', 'CanvasReleased:left'])
        ->and(array_map(fn (object $m): array => [round($m->x), round($m->y)], $dragged))->toBe([[20.0, 20.0], [50.0, 20.0], [50.0, 20.0]])
        ->and($clicked)->toBe(['CanvasClicked'])
        ->and(qtPointerKinds($right))->toBe(['RightClicked'])
        ->and([round($right[0]->x), round($right[0]->y)])->toBe([20.0, 20.0])
        ->and($older)->toBe(['RightClicked']);
});

it('stops watching when its window closes', function (): void {
    [$window] = qtPointerWindow();
    $pointer = $window->canvasPointer();
    $window->close();
    pumpFor(0.05);

    expect($pointer->watching())->toBeFalse();
});

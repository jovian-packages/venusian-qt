<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Windows\QtCanvasClicks;
use QEvent\Type;
use Surface\Contracts\Windows\Mail\View\CanvasClicked;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** Send a mouse event of $type at the global point ($gx, $gy) to the widget under it, as Qt delivers one ($widget names where the test aims). */
function mouse(QWidget $widget, Type $type, float $x, float $y, float $gx, float $gy, int $modifiers = 0, int $timestamp = 0): void
{
    $under = QApplication::widgetAt((int) round($gx), (int) round($gy)) ?? $widget;
    $local = $under->mapFromGlobal(new QPointF($gx, $gy));
    $down = $type === Type::MOUSE_BUTTON_PRESS || $type === Type::MOUSE_MOVE ? 1 : 0;
    $event = new QMouseEvent($type, $local, new QPointF($gx, $gy), $type === Type::MOUSE_MOVE ? 0 : 1, $down, $modifiers, QPointingDevice::primaryPointingDevice());
    $event->setTimestamp($timestamp);
    QCoreApplication::sendEvent($under, $event);
}

/** @return list<CanvasClicked> the canvas clicks posted since the last read */
function qtCanvasClicks(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof CanvasClicked));
}

it('posts view.clicked for a press and release on a canvas, at the press, from its top-left', function (): void {
    $window = driver()->open('main', 300, 240);
    $board = $window->column('m')->canvas('board')->minSize(200, 120);
    [$gx, $gy] = canvasShownAt($window, $board->native(), 12.0, 34.0);
    takeMail(session());
    mouse($board->native(), Type::MOUSE_BUTTON_PRESS, 12.0, 34.0, $gx, $gy);
    mouse($board->native(), Type::MOUSE_MOVE, 13.0, 34.0, $gx + 1.0, $gy);
    mouse($board->native(), Type::MOUSE_BUTTON_RELEASE, 13.0, 34.0, $gx + 1.0, $gy);

    $mail = qtCanvasClicks();
    expect($mail)->toHaveCount(1)
        ->and([$mail[0]->name(), $mail[0]->uuid])->toBe(['view.clicked.main.m.board', $board->uuid()])
        ->and([round($mail[0]->x), round($mail[0]->y)])->toBe([12.0, 34.0]);
});

it('leaves a click alone when Qt first hands its events to a window no canvas lends, as a top level\'s own QWindow', function (): void {
    $window = driver()->open('main', 300, 240);
    $board = $window->column('m')->canvas('board')->minSize(200, 120);
    [$gx, $gy] = canvasShownAt($window, $board->native(), 12.0, 34.0);
    takeMail(session());
    // Real input reaches the top level's QWindow before the widget under the pointer.
    $top = new QWindow();
    $at = fn (Type $type, int $buttons): QMouseEvent => new QMouseEvent($type, new QPointF(12.0, 34.0), new QPointF($gx, $gy), 1, $buttons, 0, QPointingDevice::primaryPointingDevice());
    QCoreApplication::sendEvent($top, $at(Type::MOUSE_BUTTON_PRESS, 1));
    mouse($board->native(), Type::MOUSE_BUTTON_PRESS, 12.0, 34.0, $gx, $gy);
    QCoreApplication::sendEvent($top, $at(Type::MOUSE_BUTTON_RELEASE, 0));
    mouse($board->native(), Type::MOUSE_BUTTON_RELEASE, 12.0, 34.0, $gx, $gy);

    expect(array_map(fn (CanvasClicked $mail): string => $mail->name(), qtCanvasClicks()))->toBe(['view.clicked.main.m.board']);
});

it('posts the second click of a double click, which Qt delivers to a widget as a double-click event, not a press', function (): void {
    $window = driver()->open('main', 300, 240);
    $board = $window->column('m')->canvas('board')->minSize(200, 120);
    [$gx, $gy] = canvasShownAt($window, $board->native(), 12.0, 34.0);
    takeMail(session());
    mouse($board->native(), Type::MOUSE_BUTTON_PRESS, 12.0, 34.0, $gx, $gy);
    mouse($board->native(), Type::MOUSE_BUTTON_RELEASE, 12.0, 34.0, $gx, $gy);
    mouse($board->native(), Type::MOUSE_BUTTON_DBL_CLICK, 12.0, 34.0, $gx, $gy);
    mouse($board->native(), Type::MOUSE_BUTTON_RELEASE, 12.0, 34.0, $gx, $gy);

    expect(qtCanvasClicks())->toHaveCount(2);
});

it('posts nothing for a drag, a release beside the canvas, or a disabled canvas', function (): void {
    $window = driver()->open('main', 300, 240);
    $column = $window->column('m');
    $board = $column->canvas('board')->minSize(200, 120);
    $beside = $column->button('beside', 'Beside');
    [$gx, $gy] = canvasShownAt($window, $board->native(), 20.0, 20.0);
    [$bx, $by] = canvasShownAt($window, $beside->native(), 4.0, 4.0);
    $drag = QGuiApplication::styleHints()->startDragDistance();
    takeMail(session());

    mouse($board->native(), Type::MOUSE_BUTTON_PRESS, 20.0, 20.0, $gx, $gy);
    mouse($board->native(), Type::MOUSE_MOVE, 20.0 + $drag + 2.0, 20.0, $gx + $drag + 2.0, $gy);
    mouse($board->native(), Type::MOUSE_BUTTON_RELEASE, 20.0, 20.0, $gx, $gy);
    mouse($board->native(), Type::MOUSE_BUTTON_PRESS, 20.0, 20.0, $gx, $gy);
    mouse($beside->native(), Type::MOUSE_BUTTON_RELEASE, 4.0, 4.0, $bx, $by);
    $column->disable();
    mouse($board->native(), Type::MOUSE_BUTTON_PRESS, 20.0, 20.0, $gx, $gy);
    mouse($board->native(), Type::MOUSE_BUTTON_RELEASE, 20.0, 20.0, $gx, $gy);

    expect(qtCanvasClicks())->toBe([]);
});

it('posts nothing for a press held past the hold interval where a hold is a right click', function (): void {
    $window = driver()->open('main', 300, 240);
    $board = $window->column('m')->canvas('board')->minSize(200, 120);
    [$gx, $gy] = canvasShownAt($window, $board->native(), 20.0, 20.0);
    // Held by event timestamps, as Qt stamps real events: no waiting, so real pointer motion cannot cancel it.
    $hold = (int) round(QGuiApplication::styleHints()->mousePressAndHoldInterval() * 1.2);
    $window->canvasClicks()->holdCancels(true);
    takeMail(session());
    mouse($board->native(), Type::MOUSE_BUTTON_PRESS, 20.0, 20.0, $gx, $gy, 0, 1000);
    mouse($board->native(), Type::MOUSE_BUTTON_RELEASE, 20.0, 20.0, $gx, $gy, 0, 1000 + $hold);
    $held = qtCanvasClicks();
    $window->canvasClicks()->holdCancels(false);
    mouse($board->native(), Type::MOUSE_BUTTON_PRESS, 20.0, 20.0, $gx, $gy, 0, 5000);
    mouse($board->native(), Type::MOUSE_BUTTON_RELEASE, 20.0, 20.0, $gx, $gy, 0, 5000 + $hold);

    expect($held)->toBe([])
        ->and(array_map(fn (CanvasClicked $mail): string => $mail->name(), qtCanvasClicks()))->toBe(['view.clicked.main.m.board']);
});

it('reads a left press without Meta (the Control key) on macOS as a click press, and stops watching when its window closes', function (): void {
    $window = driver()->open('main', 300, 240);
    $clicks = $window->canvasClicks();
    $watching = $clicks->watching();
    $window->close();
    pumpFor(0.05);

    expect([
        QtCanvasClicks::primary(1, 0, macos: true),
        QtCanvasClicks::primary(1, QtCanvasClicks::META_MODIFIER, macos: true),
        QtCanvasClicks::primary(1, QtCanvasClicks::META_MODIFIER, macos: false),
        QtCanvasClicks::primary(2, 0, macos: false),
    ])->toBe([true, false, true, false])
        ->and($watching)->toBeTrue()
        ->and($clicks->watching())->toBeFalse();
});

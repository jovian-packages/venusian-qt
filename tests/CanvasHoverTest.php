<?php

declare(strict_types=1);

use QEvent\Type;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\Windows\Mail\View\CanvasDragged;
use Surface\Contracts\Windows\Mail\View\CanvasEntered;
use Surface\Contracts\Windows\Mail\View\CanvasLeft;
use Surface\Contracts\Windows\Mail\View\CanvasMoved;
use Surface\Contracts\Windows\Mail\View\CanvasPressed;
use Surface\Contracts\Windows\Mail\View\CanvasReleased;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** The hover and press mail posted since the last read, latest-wins mail flushed first, by class. */
function hoverKinds(): array
{
    session()->flushLatest();
    $mail = array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof CanvasEntered || $mail instanceof CanvasMoved
        || $mail instanceof CanvasLeft || $mail instanceof CanvasPressed || $mail instanceof CanvasDragged || $mail instanceof CanvasReleased);

    return array_values(array_map(fn (object $mail): string => (new ReflectionClass($mail))->getShortName(), $mail));
}

/** A shown canvas above a label, watching unless told not to; global points inside it and on the label. */
function hoverWindow(bool $watch = true): array
{
    $window = driver()->open('main', 300, 260);
    $column = $window->column('m');
    $board = $column->canvas('board')->minSize(200, 120);
    $below = $column->label('below', 'below');
    if ($watch) {
        $board->watchPointer();
    }
    $inside = canvasShownAt($window, $board->native(), 20.0, 30.0);
    $further = [$inside[0] + 5.0, $inside[1]];
    $origin = $below->native()->mapToGlobal(new QPointF(5.0, 5.0));
    hoverKinds();

    return [$window, $board, $inside, $further, [$origin->x(), $origin->y()], $below];
}

/** A move with no button held, sent to $to at a global point. No QEnterEvent constructor is bound: a move over a canvas is its enter too. */
function hoverMove(QObject $to, array $at, int $buttons = 0): void
{
    $local = $to instanceof QWidget ? $to->mapFromGlobal(new QPointF(...$at)) : new QPointF(0.0, 0.0);
    QCoreApplication::sendEvent($to, new QMouseEvent(Type::MOUSE_MOVE, $local, new QPointF(...$at), 0, $buttons, 0, QPointingDevice::primaryPointingDevice()));
}

/** A button event of $type sent to $to at a global point, as Qt delivers one. */
function hoverButton(QWidget $to, Type $type, array $at, int $button, int $buttons): void
{
    QCoreApplication::sendEvent($to, new QMouseEvent($type, $to->mapFromGlobal(new QPointF(...$at)), new QPointF(...$at), $button, $buttons, 0, QPointingDevice::primaryPointingDevice()));
}

function hoverCross(QObject $to, Type $type): void
{
    QCoreApplication::sendEvent($to, new QEvent($type));
}

it('tracks the mouse on a canvas so moves with no button arrive', function (): void {
    [$window, $board] = hoverWindow(watch: false);

    expect($board->native()->testAttribute(Qt\WidgetAttribute::MOUSE_TRACKING))->toBeTrue();
    $window->close();
});

it('posts Entered, Moved and Left as the pointer crosses a watching canvas', function (): void {
    [$window, $board, $inside, $further] = hoverWindow();

    hoverMove($board->native(), $inside);
    session()->flushLatest();
    $first = array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof CanvasEntered || $mail instanceof CanvasMoved));
    hoverMove($board->native(), $further);
    hoverCross($board->native(), Type::LEAVE);

    expect(array_map(fn (object $mail): string => (new ReflectionClass($mail))->getShortName(), $first))->toBe(['CanvasEntered', 'CanvasMoved'])
        ->and($first[1])->toEqual(new CanvasMoved('main', 'm.board', $board->uuid(), 20.0, 30.0, new Modifiers()))
        ->and(hoverKinds())->toBe(['CanvasMoved', 'CanvasLeft']);
    $window->close();
});

it('takes a move over a canvas as its enter, and ignores a child\'s crossings', function (): void {
    [$window, $board, $inside] = hoverWindow();
    $child = array_values(array_filter($board->native()->children(), fn (QObject $object): bool => $object instanceof QWidget))[0];

    hoverMove($board->native(), $inside);
    hoverCross($child, Type::LEAVE);

    expect(hoverKinds())->toBe(['CanvasEntered', 'CanvasMoved']);
    $window->close();
});

it('posts nothing from a canvas that is not watching', function (): void {
    [$window, $board, $inside] = hoverWindow(watch: false);

    hoverMove($board->native(), $inside);
    hoverCross($board->native(), Type::LEAVE);

    expect(hoverKinds())->toBe([]);
    $window->close();
});

it('posts no hover during an owned press, and Left after Released for a release outside', function (): void {
    [$window, $board, $inside, , $outside] = hoverWindow();
    hoverMove($board->native(), $inside);
    hoverKinds();
    $under = QApplication::widgetAt((int) round($inside[0]), (int) round($inside[1]));

    hoverButton($under, Type::MOUSE_BUTTON_PRESS, $inside, 1, 1);
    hoverButton($under, Type::MOUSE_MOVE, $outside, 0, 1);
    hoverCross($board->native(), Type::LEAVE);
    hoverButton($under, Type::MOUSE_BUTTON_RELEASE, $outside, 1, 0);

    expect(hoverKinds())->toBe(['CanvasPressed', 'CanvasDragged', 'CanvasReleased', 'CanvasLeft']);
    $window->close();
});

it('posts nothing for a removed canvas', function (): void {
    [$window, $board, $inside] = hoverWindow();
    hoverMove($board->native(), $inside);
    hoverKinds();
    $native = $board->native();

    $board->remove();
    hoverCross($native, Type::LEAVE);

    expect(hoverKinds())->toBe([]);
    $window->close();
});

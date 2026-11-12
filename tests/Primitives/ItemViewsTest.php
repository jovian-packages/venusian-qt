<?php

declare(strict_types=1);

use QEvent\Type;
use Surface\Contracts\Windows\Mail\View\ItemActionClicked;
use Surface\Contracts\Windows\Mail\View\ItemActivated;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Surface\Contracts\Windows\Primitives\CollectionItem;
use Surface\Contracts\Windows\Primitives\ListRow;

// No window is presented: Qt paints rows only when shown, so the painted rows are a GUI run's to
// check. These cover the widgets, the models, the picture cache, the action hit test and the mail.

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** A protected method of $object, called as the native would. */
function callNative(object $object, string $method, mixed ...$args): mixed
{
    return Closure::bind(fn () => $this->{$method}(...$args), $object, $object::class)();
}

function thumbFile(): string
{
    $file = sys_get_temp_dir().'/surface-item-thumb.png';
    if (! is_file($file)) {
        $image = imagecreatetruecolor(30, 20);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 40));
        imagepng($image, $file);
    }

    return $file;
}

function releaseAt(float $x, float $y): QMouseEvent
{
    return new QMouseEvent(Type::MOUSE_BUTTON_RELEASE, new QPointF($x, $y), new QPointF($x, $y), 1, 0, 0, QPointingDevice::primaryPointingDevice());
}

it('holds one checkable, auto-exclusive button per segment and posts SelectionChanged for a user check only', function (): void {
    $window = driver()->open('main', 400, 200);
    $control = $window->column('m')->segmentedControl('s', ['Today', 'Recently', 'APODdex'], 0);
    $buttons = $control->buttons();

    expect(array_map(fn (QToolButton $b): string => $b->text(), $buttons))->toBe(['Today', 'Recently', 'APODdex'])
        ->and($buttons[0]->isChecked())->toBeTrue()
        ->and($buttons[1]->autoExclusive())->toBeTrue();

    $control->setSegment(0, 'Yesterday')->select(2);
    expect($buttons[0]->text())->toBe('Yesterday')
        ->and($buttons[2]->isChecked())->toBeTrue()
        ->and($buttons[0]->isChecked())->toBeFalse()
        ->and(takeMail(session()))->toBe([]);

    $buttons[1]->click();
    expect(takeMail(session()))->toEqual([new SelectionChanged('main', 'm.s', $control->uuid(), 1, 'Recently')])
        ->and($buttons[2]->isChecked())->toBeFalse();
});

it('keeps one model row per collection item and crops pictures to cover once', function (): void {
    $window = driver()->open('main', 600, 400);
    $view = $window->column('m')->collectionView('c', [new CollectionItem(thumbFile(), 'a'), new CollectionItem(null, 'b')]);
    $view->setCellSize(120, 90);

    expect($view->native()->viewMode())->toBe(QListView\ViewMode::ICON_MODE)
        ->and(wh($view->native()->gridSize()))->toBe([132, 102])
        ->and($view->model()->rowCount())->toBe(2);
    $view->insertItems(1, [new CollectionItem(null, 'x')])->removeItems(0, 2);
    expect($view->model()->rowCount())->toBe(1);

    $cover = callNative($view, 'cover', thumbFile(), 60, 60);
    expect([$cover->width(), $cover->height()])->toBe([60, 60])
        ->and(callNative($view, 'cover', thumbFile(), 60, 60))->toBe($cover)
        ->and(callNative($view, 'cover', '/no/such.png', 60, 60))->toBeNull();
});

it('places a cell\'s status discs right to left from the action button, or from the corner without one', function (): void {
    $window = driver()->open('main', 600, 400);
    $view = $window->column('m')->collectionView('c', []);
    $view->setCellSize(220, 156);
    $cell = new QRect(0, 0, 232, 168);
    $rects = fn (CollectionItem $item) => array_map(fn (QRect $r) => [$r->x(), $r->y(), $r->width()], callNative($view, 'statusRects', $cell, $item));

    // Cell starts at the 6 margin; corner inset 6; action 26 then a 4 gap; discs 26 with 4 between.
    expect($rects(new CollectionItem(null, 'a', thumbFile(), [thumbFile(), thumbFile()])))->toBe([[134, 12, 26], [164, 12, 26]])
        ->and($rects(new CollectionItem(null, 'a', null, [thumbFile()])))->toBe([[194, 12, 26]])
        ->and($rects(new CollectionItem(null, 'a')))->toBe([]);
});

it('posts ItemActionClicked for a release on an enabled row\'s action, and ItemActivated for enabled rows only', function (): void {
    $window = driver()->open('main', 600, 400);
    $view = $window->column('m')->listView('l', [new ListRow(null, 'open', action: 'Open ›'), new ListRow(null, '???', action: 'Open ›', enabled: false)]);
    $view->setRowHeight(104);
    $rect = new QRect(0, 0, 500, 104);
    $action = callNative($view, 'actionRect', $rect, $view->item(0));
    $centre = [$action->x() + $action->width() / 2, $action->y() + $action->height() / 2];

    expect(callNative($view, 'itemEvent', releaseAt(...$centre), $rect, 0))->toBeTrue()
        ->and(callNative($view, 'itemEvent', releaseAt(10.0, 10.0), $rect, 0))->toBeFalse()
        ->and(callNative($view, 'itemEvent', releaseAt(...$centre), $rect, 1))->toBeFalse()
        ->and(wh(callNative($view, 'itemSize')))->toBe([200, 104]);

    callNative($view, 'activated', 0);
    callNative($view, 'activated', 1);
    expect(takeMail(session()))->toEqual([
        new ItemActionClicked('main', 'm.l', $view->uuid(), 0),
        new ItemActivated('main', 'm.l', $view->uuid(), 0),
    ]);
});

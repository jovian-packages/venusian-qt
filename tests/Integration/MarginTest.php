<?php

declare(strict_types=1);

use Surface\Contracts\Toolkits\UI\Align;

/*
| A kind that keeps no margin of its own (column, row, overlay, image, ...) has its margin kept
| by its parent: a column, row, overlay and grid hold it in a nested layout whose contents
| margins are the margin, and a fixed moves its frame. The sheet-margin kinds (label, link,
| check box, text input, progress bar) keep theirs, so their parent adds none.
*/

/**
 * Geometry of a primitive's widget, in its parent widget's coordinates: [x, y, width, height].
 *
 * @return array{int, int, int, int}
 */
function marginPlaced(object $primitive): array
{
    return (function (): array {
        $g = $this->native()->geometry();

        return [$g->x(), $g->y(), $g->width(), $g->height()];
    })->call($primitive);
}

afterEach(fn () => closeAll());

it('keeps a margin round a column in a column, the next child after it', function (): void {
    $window = openWindow('main', 600, 400);
    $root = $window->column('root');
    $before = $root->column('before')->minSize(0, 20);
    $held = $root->column('held')->minSize(0, 40)->setMargin('5 7 11 13');
    $after = $root->column('after')->minSize(0, 20);
    pumpFor(0.4);

    [, $by, , $bh] = marginPlaced($before);
    [$x, $y, $w, $h] = marginPlaced($held);
    [, $ay] = marginPlaced($after);

    expect([$x, $w, $h])->toBe([13, 600 - 13 - 7, 40])
        ->and($y)->toBe($by + $bh + 5)
        ->and($ay)->toBe($y + $h + 11);
});

it('keeps a margin round an aligned column in a column', function (): void {
    $window = openWindow('main', 600, 400);
    $held = $window->column('root')->column('held')->minSize(100, 30)->align(Align::END, Align::START)->setMargin('0 12');
    pumpFor(0.4);

    [$x, , $w] = marginPlaced($held);

    expect($x + $w)->toBe(600 - 12);
});

it('keeps a margin round a column in a row', function (): void {
    $window = openWindow('main', 600, 400);
    $row = $window->column('root')->row('row');
    $first = $row->column('first')->minSize(50, 30);
    $held = $row->column('held')->minSize(50, 30)->setMargin('4 0 0 9');
    pumpFor(0.4);

    [$fx, , $fw] = marginPlaced($first);
    [$x, $y] = marginPlaced($held);

    expect($x)->toBe($fx + $fw + 9)
        ->and($y)->toBe(4);
});

it('places a margined child in an overlay\'s corner, in by its margin', function (): void {
    $window = openWindow('main', 600, 400);
    $overlay = $window->column('root')->overlay('overlay')->minSize(0, 200);
    $overlay->column('back')->fill();
    $badge = $overlay->column('badge')->minSize(30, 30)->align(Align::END, Align::START)->setMargin(12);
    pumpFor(0.4);

    [, , $ow] = marginPlaced($overlay);
    [$x, $y, $w, $h] = marginPlaced($badge);

    expect([$x + $w, $y, $w, $h])->toBe([$ow - 12, 12, 30, 30]);
});

it('keeps a margin round a picture in an overlay', function (): void {
    $window = openWindow('main', 600, 400);
    $overlay = $window->column('root')->overlay('overlay')->minSize(0, 200);
    $image = $overlay->image('icon')->minSize(16, 16)->maxSize(16, 16)->align(Align::START, Align::END)->setMargin('0 0 7 5');
    pumpFor(0.4);

    [, , , $oh] = marginPlaced($overlay);
    [$x, $y, , $h] = marginPlaced($image);

    expect([$x, $y + $h])->toBe([5, $oh - 7]);
});

it('keeps a margin round a column in a grid cell', function (): void {
    $window = openWindow('main', 600, 400);
    $grid = $window->column('root')->grid('grid');
    $grid->at(0, 0)->column('first')->minSize(60, 30);
    $held = $grid->at(0, 1)->column('held')->minSize(60, 30)->align(Align::START, Align::START)->setMargin('6 0 0 8');
    pumpFor(0.4);

    [$x, $y] = marginPlaced($held);

    expect($x)->toBe(60 + 8)
        ->and($y)->toBe(6);
});

it('moves a child of a fixed in by its margin, and reaches past it', function (): void {
    $window = openWindow('main', 600, 400);
    $fixed = $window->column('root')->fixed('fixed');
    $held = $fixed->at(10, 20, 50, 40)->column('held')->setMargin('3 4 5 6');
    pumpFor(0.4);

    $minimum = (fn () => [$this->native()->minimumWidth(), $this->native()->minimumHeight()])->call($fixed);

    expect(marginPlaced($held))->toBe([16, 23, 50, 40])
        ->and($minimum)->toBe([10 + 6 + 50 + 4, 20 + 3 + 40 + 5]);
});

it('follows a margin changed live, and drops the holder when it goes', function (): void {
    $window = openWindow('main', 600, 400);
    $root = $window->column('root');
    $held = $root->column('held')->minSize(0, 30);
    $after = $root->column('after')->minSize(0, 20);
    pumpFor(0.3);
    expect(marginPlaced($held)[0])->toBe(0);

    $held->setMargin('10 20');
    pumpFor(0.3);
    [$x, $y, $w, $h] = marginPlaced($held);
    expect([$x, $y, $w])->toBe([20, 10, 560])
        ->and(marginPlaced($after)[1])->toBe($y + $h + 10);

    $held->setMargin('2 3');
    pumpFor(0.3);
    expect(array_slice(marginPlaced($held), 0, 3))->toBe([3, 2, 594]);

    $held->setMargin(0);
    pumpFor(0.3);
    expect(array_slice(marginPlaced($held), 0, 3))->toBe([0, 0, 600])
        ->and(marginPlaced($after)[1])->toBe(30);
});

it('gives a hidden child\'s margin no room, and gives it back when shown', function (): void {
    $window = openWindow('main', 600, 400);
    $root = $window->column('root');
    $hidden = $root->column('hidden')->minSize(0, 30)->setMargin(15);
    $after = $root->column('after')->minSize(0, 20);
    $hidden->hide();
    pumpFor(0.3);
    expect(marginPlaced($after)[1])->toBe(0);

    $hidden->show();
    pumpFor(0.3);
    expect(marginPlaced($after)[1])->toBe(15 + 30 + 15);
});

it('gives a hidden overlay child\'s margin no say in the overlay\'s size', function (): void {
    $window = openWindow('main', 600, 400);
    $overlay = $window->column('root')->overlay('overlay')->align(Align::START, Align::START);
    $overlay->column('small')->minSize(40, 40);
    $big = $overlay->column('big')->minSize(40, 40)->setMargin(30);
    pumpFor(0.3);
    expect(array_slice(marginPlaced($overlay), 2))->toBe([100, 100]);

    $big->hide();
    pumpFor(0.3);
    expect(array_slice(marginPlaced($overlay), 2))->toBe([40, 40]);
});

it('adds nothing round a kind that keeps its own margin', function (): void {
    $window = openWindow('main', 600, 400);
    $root = $window->column('root');
    $plain = $root->label('plain', 'text');
    pumpFor(0.3);
    [, , , $plainHeight] = marginPlaced($plain);

    $plain->setMargin('5 0 11 0');
    pumpFor(0.3);
    [$x, $y, , $h] = marginPlaced($plain);

    // The label's sheet box holds the margin inside its own widget: the column adds none.
    expect([$x, $y, $h])->toBe([0, 0, $plainHeight + 16]);
});

it('keeps a tab page in from its margin, live', function (): void {
    $window = openWindow('main', 600, 400);
    $tabs = $window->column('root')->tabs('tabs')->fill();
    $page = $tabs->addPage('one', 'One');
    $page->setMargin('4 6 8 10');
    pumpFor(0.4);
    [$x, $y, $w] = marginPlaced($page);
    expect([$x, $y])->toBe([10, 4]);

    $page->setMargin(0);
    pumpFor(0.3);
    expect(array_slice(marginPlaced($page), 0, 3))->toBe([0, 0, $w + 16]);
});

it('keeps a split pane in from its margin, and gives a hidden pane no room', function (): void {
    $window = openWindow('main', 600, 400);
    $split = $window->column('root')->split('split')->fill();
    $first = $split->column('first');
    $second = $split->column('second')->setMargin(9);
    pumpFor(0.4);
    [$x, $y] = marginPlaced($second);
    expect([$x, $y])->toBe([9, 9]);

    $second->hide();
    pumpFor(0.3);
    expect(marginPlaced($first)[2])->toBe(600);
});

it('keeps a scroll view\'s content in from its margin, live', function (): void {
    $window = openWindow('main', 600, 400);
    $scroll = $window->column('root')->scrollView('scroll')->fill();
    $content = $scroll->column('content')->minSize(0, 800)->setMargin('5 0 0 7');
    pumpFor(0.4);
    [$x, $y, $w] = marginPlaced($content);
    expect([$x, $y])->toBe([7, 5]);

    $content->setMargin(0);
    pumpFor(0.3);
    expect(array_slice(marginPlaced($content), 0, 3))->toBe([0, 0, $w + 7]);
});

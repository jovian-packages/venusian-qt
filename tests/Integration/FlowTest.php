<?php

declare(strict_types=1);

use Surface\Contracts\Toolkits\UI\Insets;

/*
| Qt has no wrapping layout for widgets: the flow places each child at its size hint, from the
| left, onto the next line where the width runs out, and sets its own minimum height to what
| its lines need. Read back from the widgets' real geometry.
*/

afterEach(fn () => closeAll());

/** A flow of $count buttons in a window $width wide. */
function flowOfButtons(int $width, int $count = 6, int $spacing = 0, Insets|int $padding = 0): array
{
    $window = openWindow('main', $width, 400);
    $flow = $window->column('root')->flow('tags', $spacing, $padding);
    for ($i = 0; $i < $count; $i++) {
        $flow->button("b{$i}", "Tag number {$i}");
    }
    pumpFor(0.3);

    return [$window, $flow];
}

/** @return list<array{int, int, int, int}> x, y, width, height of each child widget */
function frames(object $flow): array
{
    return array_map(static function ($child): array {
        $g = $child->native()->geometry();

        return [$g->x(), $g->y(), $g->width(), $g->height()];
    }, $flow->children());
}

it('puts the children side by side at their own size on one line when the width allows', function (): void {
    [, $flow] = flowOfButtons(1400);

    $frames = frames($flow);
    expect(array_unique(array_column($frames, 1)))->toHaveCount(1)
        ->and($frames[0][0])->toBe(0);
    foreach ($flow->children() as $i => $child) {
        expect($frames[$i][2])->toBe($child->native()->sizeHint()->width());
        if ($i > 0) {
            expect($frames[$i][0])->toBe($frames[$i - 1][0] + $frames[$i - 1][2]);
        }
    }
});

it('wraps onto a second line, from the left, where the width runs out', function (): void {
    [, $flow] = flowOfButtons(300);

    $frames = frames($flow);
    $lines = array_values(array_unique(array_column($frames, 1)));
    expect(count($lines))->toBeGreaterThan(1);
    foreach ($frames as [$x, $y, $w]) {
        expect($x + $w)->toBeLessThanOrEqual(300);
    }
    foreach ($lines as $y) {
        $first = array_values(array_filter($frames, fn (array $f): bool => $f[1] === $y))[0];
        expect($first[0])->toBe(0);
    }
});

it('keeps the gap on a line and between lines as spacing', function (): void {
    [, $flow] = flowOfButtons(300, 6, 9);

    $frames = frames($flow);
    $second = array_values(array_filter($frames, fn (array $f): bool => $f[1] === $frames[0][1]))[1];
    $lines = array_values(array_unique(array_column($frames, 1)));
    expect($second[0])->toBe($frames[0][0] + $frames[0][2] + 9)
        ->and($lines[1])->toBe($lines[0] + $frames[0][3] + 9);
});

it('keeps the padding round the lines', function (): void {
    [, $flow] = flowOfButtons(300, 6, 0, Insets::of([5, 7, 11, 13]));

    $frames = frames($flow);
    $lines = array_values(array_unique(array_column($frames, 1)));
    $lastLine = end($lines);
    expect($frames[0][0])->toBe(13)
        ->and($frames[0][1])->toBe(5);
    foreach ($frames as [$x, $y, $w]) {
        expect($x + $w)->toBeLessThanOrEqual(300 - 7);
    }
    expect($flow->native()->minimumHeight())->toBe($lastLine + $frames[0][3] + 11);
});

it('grows its height with the lines, and a column holding it grows with it', function (): void {
    [$window, $flow] = flowOfButtons(1400);
    $oneLine = $flow->native()->minimumHeight();
    expect($oneLine)->toBe($flow->children()[0]->native()->sizeHint()->height());

    nativeResize($window, 300, 400);
    pumpFor(0.4);
    $wrapped = $flow->native()->minimumHeight();

    expect($wrapped)->toBeGreaterThan($oneLine)
        ->and($flow->native()->height())->toBeGreaterThanOrEqual($wrapped);

    nativeResize($window, 1400, 400);
    pumpFor(0.4);
    expect($flow->native()->minimumHeight())->toBe($oneLine);
});

it('gives a hidden child no room, and the room back when it is shown', function (): void {
    [, $flow] = flowOfButtons(300, 3);
    $before = frames($flow);

    $flow->children()[0]->hide();
    pumpFor(0.2);
    $hidden = frames($flow);
    expect($hidden[1][0])->toBe(0)
        ->and($hidden[1][1])->toBe(0);

    $flow->children()[0]->show();
    pumpFor(0.2);
    expect(frames($flow))->toBe($before);
});

it('places a child with a margin inside it, and the margin takes room', function (): void {
    [, $flow] = flowOfButtons(1400, 2);
    $flow->children()[0]->setMargin(Insets::of([4, 6, 8, 10]));
    pumpFor(0.2);

    $frames = frames($flow);
    expect($frames[0][0])->toBe(10)
        ->and($frames[0][1])->toBe(4)
        ->and($frames[1][0])->toBe(10 + $frames[0][2] + 6);
});

it('follows a reorder', function (): void {
    [, $flow] = flowOfButtons(1400, 3);
    $first = $flow->children()[0];

    $first->moveTo(2);
    pumpFor(0.2);

    $frames = frames($flow);
    expect(array_map(fn ($c): string => $c->name(), $flow->children()))->toBe(['b1', 'b2', 'b0'])
        ->and($flow->children()[0]->native()->geometry()->x())->toBe(0)
        ->and($first->native()->geometry()->x())->toBeGreaterThan($frames[1][0]);
});

it('takes a child out and places the rest again', function (): void {
    [, $flow] = flowOfButtons(1400, 3);

    $flow->children()[0]->remove();
    pumpFor(0.2);

    expect(frames($flow)[0][0])->toBe(0)
        ->and(count($flow->children()))->toBe(2);
});

it('is at least as wide as its widest child plus padding without fill, and a hidden widest child no longer counts', function (): void {
    $window = openWindow('main', 600, 300);
    $flow = $window->column('root')->row('strip')->flow('tags', 4, Insets::of([0, 7, 0, 13]));
    $flow->button('narrow', 'Go');
    $wide = $flow->button('wide', 'A much wider button label');
    pumpFor(0.3);

    $wideBox = $wide->native()->sizeHint()->width();
    expect($flow->native()->width())->toBeGreaterThanOrEqual($wideBox + 20)
        ->and($flow->native()->minimumWidth())->toBe($wideBox + 20);

    $wide->hide();
    pumpFor(0.3);
    $narrowBox = $flow->children()[0]->native()->sizeHint()->width();
    expect($flow->native()->minimumWidth())->toBe($narrowBox + 20);
});

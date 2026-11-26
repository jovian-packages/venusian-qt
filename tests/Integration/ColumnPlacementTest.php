<?php

declare(strict_types=1);

use Surface\Contracts\Toolkits\UI\Align;

/**
 * Geometry of a primitive's widget, in its parent's coordinates: [x, y, width, height].
 *
 * @return array{int, int, int, int}
 */
function placed(object $primitive): array
{
    return (function (): array {
        $g = $this->native()->geometry();

        return [$g->x(), $g->y(), $g->width(), $g->height()];
    })->call($primitive);
}

const LONG_TEXT = 'The quick brown fox jumps over the lazy dog near the quiet river bank. '
    .'The quick brown fox jumps over the lazy dog near the quiet river bank. '
    .'The quick brown fox jumps over the lazy dog near the quiet river bank. '
    .'The quick brown fox jumps over the lazy dog near the quiet river bank. '
    .'The quick brown fox jumps over the lazy dog near the quiet river bank.';

it('reserves a centred, capped column the height of its wrapped text at its own width', function (): void {
    // In a scroll view the content is exactly as tall as it asks to be: no spare room hides a
    // shortfall. Asked at the whole width, the text column would get too little height, and
    // the block that cannot shrink would push the credit up over it.
    $window = openWindow('main', 700, 300);
    $scroll = $window->column('root')->scrollView('scroll')->setScrollbars(false, true)->fill();
    $content = $scroll->column('content');
    $story = $content->column('story', 10)->fill(true, false)->align(Align::CENTER, Align::START)->maxSize(300, 0);
    $story->label('text', LONG_TEXT)->setWrap(true);
    $block = $story->column('block')->minSize(0, 200);       // cannot shrink, like a web view
    $credit = $story->label('credit', 'credit');
    pumpFor(0.5);

    [, , $width] = placed($content);
    [$sx, , $sw] = placed($story);
    [, $by, , $bh] = placed($block);
    [, $cy] = placed($credit);

    expect($sw)->toBe(300)
        ->and(abs($sx - intdiv($width - $sw, 2)))->toBeLessThanOrEqual(1)
        ->and($cy)->toBeGreaterThanOrEqual($by + $bh);
});

it('places a child left, right, centred, or filling up to its maximum', function (): void {
    $window = openWindow('main', 600, 400);
    $column = $window->column('root');
    $start = $column->label('start', 'start')->align(Align::START, Align::START);
    $end = $column->label('end', 'end')->align(Align::END, Align::START);
    $center = $column->label('center', 'center')->align(Align::CENTER, Align::START);
    $capped = $column->label('capped', 'capped')->maxSize(250, 0);
    pumpFor(0.5);

    [$sx] = placed($start);
    [$ex, , $ew] = placed($end);
    [$cx, , $cw] = placed($center);
    [$fx, , $fw] = placed($capped);

    expect($sx)->toBe(0)
        ->and($ex + $ew)->toBe(600)
        ->and(abs($cx - intdiv(600 - $cw, 2)))->toBeLessThanOrEqual(1)
        ->and([$fx, $fw])->toBe([0, 250]);
});

it('keeps a child in its place when its alignment changes, and moves it with its row', function (): void {
    $window = openWindow('main', 500, 400);
    $column = $window->column('root');
    $a = $column->label('a', 'a');
    $b = $column->label('b', 'b')->align(Align::CENTER, Align::START);
    $c = $column->label('c', 'c');
    pumpFor(0.3);
    $order = fn (): array => array_map(fn (object $p): int => placed($p)[1], [$a, $b, $c]);

    [$ay, $by, $cy] = $order();
    expect($ay < $by && $by < $cy)->toBeTrue();

    $b->align(Align::FILL, Align::START);                    // out of its row
    pumpFor(0.3);
    [$ay, $by, $cy] = $order();
    expect($ay < $by && $by < $cy)->toBeTrue()
        ->and(placed($b)[2])->toBe(500);

    $b->align(Align::END, Align::START);                     // into a row again
    pumpFor(0.3);
    [$ay, $by, $cy] = $order();
    expect($ay < $by && $by < $cy)->toBeTrue()
        ->and(placed($b)[0] + placed($b)[2])->toBe(500);

    $b->moveTo(0);                                           // the row moves with it
    pumpFor(0.3);
    [$ay, $by, $cy] = $order();
    expect($by < $ay && $ay < $cy)->toBeTrue();
});

it('takes a child in a row out cleanly', function (): void {
    $window = openWindow('main', 500, 400);
    $column = $window->column('root');
    $a = $column->label('a', 'a');
    $b = $column->label('b', 'b')->align(Align::CENTER, Align::START);
    $c = $column->label('c', 'c');
    pumpFor(0.3);

    $b->remove();
    pumpFor(0.3);
    $column->label('d', 'd')->align(Align::END, Align::START);
    pumpFor(0.3);

    [, $ay] = placed($a);
    [, $cy] = placed($c);
    expect($ay < $cy)->toBeTrue();
});

<?php

declare(strict_types=1);

use Qt\TextFormat;
use Surface\Contracts\Toolkits\Styling\TextRun;

/*
| A QLabel never elides: truncating, the label shows its text cut with "…" to its width
| (QFontMetricsF::elidedText), again on each resize, and never widens what holds it.
*/

const TRUNCATE_LONG = 'A title far longer than the narrow window it is shown in, so it must be cut';

afterEach(fn () => closeAll());

it('cuts a long title with an ellipsis at the window\'s width instead of widening it', function (): void {
    $window = openWindow('main', 200, 120);
    $label = $window->column('root')->label('title', TRUNCATE_LONG)->setTruncate(true);
    pumpFor(0.4);

    $native = $label->native();
    expect($window->size()[0])->toBe(200)
        ->and($native->width())->toBeLessThanOrEqual(200)
        ->and($native->text())->toStartWith('A title')
        ->and($native->text())->toEndWith('…')
        ->and($native->textFormat())->toBe(TextFormat::PLAIN_TEXT)
        ->and($label->text())->toBe(TRUNCATE_LONG);
});

it('shows more of the text when the window grows, and all of it when it fits', function (): void {
    $window = openWindow('main', 200, 120);
    $label = $window->column('root')->label('title', TRUNCATE_LONG)->setTruncate(true);
    pumpFor(0.4);
    $narrow = mb_strlen($label->native()->text());

    nativeResize($window, 400, 120);
    pumpFor(0.4);
    expect(mb_strlen($label->native()->text()))->toBeGreaterThan($narrow);

    nativeResize($window, 1200, 120);
    pumpFor(0.4);
    expect($label->native()->text())->toBe(TRUNCATE_LONG);
});

it('wins over wrap, and gives the wrap and the whole text back when it stops', function (): void {
    $window = openWindow('main', 200, 300);
    $label = $window->column('root')->label('title', TRUNCATE_LONG)->setWrap(true)->setTruncate(true);
    pumpFor(0.4);
    expect($label->native()->wordWrap())->toBeFalse();

    $label->setTruncate(false);
    pumpFor(0.4);

    expect($label->native()->wordWrap())->toBeTrue()
        ->and($label->native()->text())->toBe(TRUNCATE_LONG)
        ->and($window->size()[0])->toBe(200);
});

it('cuts runs run by run, as rich text', function (): void {
    $window = openWindow('main', 200, 120);
    $label = $window->column('root')->label('title', '');
    $label->setRuns([new TextRun('Bold lead: ', bold: true), new TextRun(TRUNCATE_LONG)])->setTruncate(true);
    pumpFor(0.4);

    $html = $label->native()->text();
    expect($label->native()->textFormat())->toBe(TextFormat::RICH_TEXT)
        ->and($html)->toContain('<b>Bold lead: </b>')
        ->and($html)->toContain('…')
        ->and($html)->not->toContain('must be cut');
});

it('needs the cut: whole, the text asks for more than the window', function (): void {
    $window = openWindow('main', 200, 120);
    $label = $window->column('root')->label('title', TRUNCATE_LONG);
    pumpFor(0.2);

    expect($label->native()->sizeHint()->width())->toBeGreaterThan(200);
});

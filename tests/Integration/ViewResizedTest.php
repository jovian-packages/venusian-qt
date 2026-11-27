<?php

declare(strict_types=1);

use Surface\Contracts\Toolkits\Mail\Views\ViewResized;

/*
| A view that watches its size hears its widget's Resize events (a QEventFilter) and posts
| ViewResized with the size the toolkit laid it out at, once per pump.
*/

afterEach(fn () => closeAll());

/** @return list<ViewResized> */
function resizedMail(array $mail): array
{
    return array_values(array_filter($mail, fn (object $m): bool => $m instanceof ViewResized));
}

it('posts ViewResized with the size a watched view is laid out at, and again at a new size', function (): void {
    $window = openWindow('main', 300, 200);
    $row = $window->column('root')->row('strip')->fill()->watchSize();
    pumpFor(0.2);
    takeMail();

    nativeResize($window, 500, 260);
    $mail = resizedMail(mailUntil('ViewResized'));

    $size = $row->native()->size();
    expect($mail)->not->toBeEmpty()
        ->and([end($mail)->width, end($mail)->height])->toBe([$size->width(), $size->height()])
        ->and(end($mail)->width)->toBeGreaterThan(300)
        ->and(end($mail)->path)->toBe($row->path());
});

it('posts nothing for a size it has already posted', function (): void {
    $window = openWindow('main', 300, 200);
    $row = $window->column('root')->row('strip')->fill()->watchSize();
    pumpFor(0.2);
    takeMail();

    $row->native()->resize($row->native()->width(), $row->native()->height());
    pumpFor(0.2);

    expect(resizedMail(takeMail()))->toBe([]);
});

it('posts nothing after the view stops watching', function (): void {
    $window = openWindow('main', 300, 200);
    $row = $window->column('root')->row('strip')->fill()->watchSize();
    pumpFor(0.2);
    $row->watchSize(false);
    takeMail();

    nativeResize($window, 520, 300);
    pumpFor(0.3);

    expect(resizedMail(takeMail()))->toBe([]);
});

it('posts again once it is turned back on', function (): void {
    $window = openWindow('main', 300, 200);
    $row = $window->column('root')->row('strip')->fill()->watchSize();
    pumpFor(0.2);
    $row->watchSize(false);
    takeMail();
    $row->watchSize();

    nativeResize($window, 480, 200);

    expect(resizedMail(mailUntil('ViewResized')))->not->toBeEmpty();
});

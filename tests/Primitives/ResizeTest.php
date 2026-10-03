<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\View\ViewResized;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

// Leave Qt quiet for the files after this one: an open window keeps posting layout and paint work.
afterEach(function (): void {
    driver()->closeAll();
    pumpFor(0.1);
    takeMail(session());
});

/** @return list<ViewResized> */
function viewResizes(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $m): bool => $m instanceof ViewResized));
}

it('posts ViewResized only for watched leaves, coalesced', function (): void {
    $window = driver()->open('main', 400, 300);
    $main = $window->column('m');
    $watched = $main->label('w', 'W')->fill(vertical: false)->watchSize();
    $silent = $main->label('s', 'S')->fill(vertical: false);
    $window->present();
    pumpFor(0.2);
    takeMail(session());
    $window->native()->resize(500, 300);
    $window->native()->resize(520, 300);
    pumpFor(0.2);

    $resized = viewResizes();
    expect($resized)->toHaveCount(1)
        ->and($resized[0]->path())->toBe('m.w')
        ->and($resized[0]->uuid())->toBe($watched->uuid())
        ->and($resized[0]->width)->toBe($watched->size()[0])
        ->and($watched->size()[0])->toBe(520)
        ->and($silent->size()[0])->toBe($watched->size()[0]);

    $watched->watchSize(false);
    $window->native()->resize(540, 300);
    pumpFor(0.2);
    expect(viewResizes())->toBe([]);
});

it('drops a watched view inside a removed container: no mail, no path, no uuid', function (): void {
    $window = driver()->open('main', 400, 300);
    $main = $window->column('m');
    $box = $main->column('box');
    $watched = $box->label('w', 'W')->fill(vertical: false)->watchSize();
    $window->present();
    pumpFor(0.2);
    takeMail(session());
    $window->native()->resize(500, 300);
    // A raw pump delivers the resize without flushing: the view's mail is pending when the container goes.
    session()->pump(50_000_000);
    expect(pendingLatest(session()))->toContain('view.resized.main.m.box.w');
    $box->remove();
    pumpFor(0.1);
    $window->native()->resize(520, 300);
    pumpFor(0.2);

    expect(viewResizes())->toBe([])
        ->and($watched->isRemoved())->toBeTrue()
        ->and($watched->isWatchingSize())->toBeFalse()
        ->and($window->view('m.box.w'))->toBeNull()
        ->and($window->view('m.box'))->toBeNull()
        ->and($window->uuid($watched->uuid()))->toBeNull();

    $main->remove();
    expect($window->content())->toBeNull()
        ->and($window->view('m'))->toBeNull();
});

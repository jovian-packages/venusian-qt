<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\Primitives\TKPrimitiveGroup;

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

/**
 * Make a primitive in $host, remove it, and hand back only a weak reference.
 * @param Closure(TKPrimitiveGroup): object $make
 */
function removedWeakly(TKPrimitiveGroup $host, Closure $make): WeakReference
{
    $primitive = $make($host);
    $reference = WeakReference::create($primitive);
    $primitive->remove();

    return $reference;
}

/**
 * Pump and collect until the reference clears: Qt deletes a widget's signal slots one deferred
 * pass after the widget, so a freed primitive can take a few pumps. Only a cycle never clears.
 */
function freedWithin(WeakReference $reference, float $seconds): bool
{
    $deadline = microtime(true) + $seconds;
    do {
        pumpFor(0.02);
        gc_collect_cycles();
    } while (! is_null($reference->get()) && microtime(true) < $deadline);

    return is_null($reference->get());
}

it('frees every kind of primitive once removed', function (): void {
    $window = driver()->open('main', 300, 300);
    $main = $window->column('m');
    $window->present();
    pumpFor(0.1);
    $clip = __DIR__.'/../fixtures/clip.mp4';
    $makers = [
        'column' => fn (TKPrimitiveGroup $h) => $h->column('x'),
        'row' => fn (TKPrimitiveGroup $h) => $h->row('x'),
        'grid' => fn (TKPrimitiveGroup $h) => $h->grid('x'),
        'fixed' => fn (TKPrimitiveGroup $h) => $h->fixed('x'),
        'scroll' => fn (TKPrimitiveGroup $h) => $h->scrollView('x'),
        'label' => fn (TKPrimitiveGroup $h) => $h->label('x', 'X'),
        'watched label' => fn (TKPrimitiveGroup $h) => $h->label('x', 'X')->watchSize(),
        'button' => fn (TKPrimitiveGroup $h) => $h->button('x', 'X'),
        'image' => fn (TKPrimitiveGroup $h) => $h->image('x', __DIR__.'/../fixtures/wide.png'),
        'separator' => fn (TKPrimitiveGroup $h) => $h->separator('x'),
        'progress' => fn (TKPrimitiveGroup $h) => $h->progressBar('x', 0.5),
        'input' => fn (TKPrimitiveGroup $h) => $h->textInput('x'),
        'area' => fn (TKPrimitiveGroup $h) => $h->textArea('x'),
        'checkbox' => fn (TKPrimitiveGroup $h) => $h->checkbox('x', 'X'),
        'toggle button' => fn (TKPrimitiveGroup $h) => $h->toggleButton('x', 'X'),
        'slider' => fn (TKPrimitiveGroup $h) => $h->slider('x', 0.0, 1.0, 0.5),
        'dropdown' => fn (TKPrimitiveGroup $h) => $h->dropdown('x', ['a']),
        'datepicker' => fn (TKPrimitiveGroup $h) => $h->datepicker('x'),
        'table' => fn (TKPrimitiveGroup $h) => $h->table('x', [new TableColumn('a', 'A')], [['a' => '1']]),
        'video' => fn (TKPrimitiveGroup $h) => $h->video('x', $clip),
    ];

    $alive = [];
    foreach ($makers as $kind => $make) {
        if (! freedWithin(removedWeakly($main, $make), 1.0)) {
            $alive[] = $kind;
        }
    }

    expect($alive)->toBe([]);
});

it('frees a closed window and its content', function (): void {
    $references = (function (): array {
        $window = driver()->open('main', 300, 200);
        $label = $window->column('m')->label('l', 'L');
        $window->present();
        pumpFor(0.1);
        $window->close();

        return [WeakReference::create($window), WeakReference::create($label)];
    })();
    expect(freedWithin($references[0], 1.0))->toBeTrue()
        ->and(freedWithin($references[1], 1.0))->toBeTrue();
});

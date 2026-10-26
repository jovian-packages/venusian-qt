<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Primitives\QtSplitView;
use Jovian\Toolkits\Qt\Windows\QtWindow;
use QEvent\Type;
use Surface\Bridge\ToolkitPump;
use Surface\Contracts\Windows\Mail\View\SplitMoved;
use Surface\Contracts\Windows\Mail\View\TabSelected;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

afterEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

function splitPumpUntil(Closure $done, float $seconds = 3.0): bool
{
    $pump = new ToolkitPump(session());
    $until = microtime(true) + $seconds;
    while (! $done() && microtime(true) < $until) {
        $pump->sleep(10_000_000);
    }

    return $done();
}

/**
 * Where a pane's widget starts and ends along the split, in the splitter's coordinates.
 *
 * @return array{int, int}
 */
function paneSpan(QtSplitView $split, string $pane): array
{
    $geometry = $split->view($pane)->native()->geometry();

    return $split->isHorizontal()
        ? [$geometry->x(), $geometry->x() + $geometry->width()]
        : [$geometry->y(), $geometry->y() + $geometry->height()];
}

function splitLength(QtSplitView $split): float
{
    return (float) ($split->isHorizontal() ? $split->native()->width() : $split->native()->height());
}

/** Pump until the window shows and two pumps see the same split length and pane spans. */
function settledSplit(QtWindow $window, QtSplitView $split, float $seconds = 3.0): bool
{
    $last = null;

    return splitPumpUntil(function () use ($window, $split, &$last): bool {
        $now = [splitLength($split), ...$split->native()->sizes()];
        $settled = $window->native()->isVisible() && $now[0] > 0.0 && $now === $last;
        $last = $now;

        return $settled;
    }, $seconds);
}

/** One pixel plus a handle, as a fraction of the split's length. */
function splitTolerance(QtSplitView $split): float
{
    return (1.0 + $split->native()->handleWidth()) / splitLength($split);
}

/** A three-pane split filling a 600×300 window. */
function threePanes(bool $horizontal = true): array
{
    $window = driver()->open('main', 600, 300);
    $split = $window->column('m')->split('panes', $horizontal)->fill();
    foreach (['a', 'b', 'c'] as $pane) {
        $split->column($pane)->label("{$pane}-label", strtoupper($pane));
    }

    return [$window, $split];
}

/** A left-button event on $widget at its local point, with the global point Qt maps it from. */
function splitMouse(QWidget $widget, Type $type, float $x, float $y): void
{
    $origin = $widget->mapFromGlobal(new QPointF(0.0, 0.0));
    $button = $type === Type::MOUSE_MOVE ? 0 : 1;
    $buttons = $type === Type::MOUSE_BUTTON_RELEASE ? 0 : 1;
    QCoreApplication::sendEvent($widget, new QMouseEvent($type, new QPointF($x, $y), new QPointF($x - $origin->x(), $y - $origin->y()), $button, $buttons, 0, QPointingDevice::primaryPointingDevice()));
}

it('frees a removed split', function (): void {
    [$window, $split] = threePanes(true);
    $window->present();
    expect(settledSplit($window, $split))->toBeTrue();
    $freed = WeakReference::create($split);
    $split->remove();
    unset($split);
    pumpFor(0.1);
    gc_collect_cycles();

    expect($freed->get())->toBeNull();
});

it('round-trips divider positions within a pixel and a handle of the split length, and posts nothing for them', function (bool $horizontal): void {
    [$window, $split] = threePanes($horizontal);
    $window->present();
    expect(settledSplit($window, $split))->toBeTrue();
    $tolerance = splitTolerance($split);
    expect(abs($split->positions()[0] - 1 / 3))->toBeLessThanOrEqual($tolerance)   // the first layout shares the length
        ->and(abs($split->positions()[1] - 2 / 3))->toBeLessThanOrEqual($tolerance);

    $split->setPositions([0.25, 0.6]);
    expect(settledSplit($window, $split))->toBeTrue();
    $positions = $split->positions();

    expect($positions)->toHaveCount(2)
        ->and(abs($positions[0] - 0.25))->toBeLessThanOrEqual($tolerance)
        ->and(abs($positions[1] - 0.6))->toBeLessThanOrEqual($tolerance)
        ->and(abs(paneSpan($split, 'a')[1] / splitLength($split) - $positions[0]))->toBeLessThanOrEqual(1.0 / splitLength($split))
        ->and(viewMail(session()))->toBe([]);
})->with(['side by side' => true, 'stacked' => false]);

it('applies positions set before the split is on screen at its first layout', function (): void {
    [$window, $split] = threePanes();
    $split->setPositions([0.3, 0.7]);
    $window->present();
    expect(settledSplit($window, $split))->toBeTrue();
    $tolerance = splitTolerance($split);

    expect(abs($split->positions()[0] - 0.3))->toBeLessThanOrEqual($tolerance)
        ->and(abs($split->positions()[1] - 0.7))->toBeLessThanOrEqual($tolerance);
});

it('follows the visible panes when the middle one hides, and shows it again', function (): void {
    [$window, $split] = threePanes();
    $window->present();
    expect(settledSplit($window, $split))->toBeTrue();

    $split->view('b')->hide();
    expect(settledSplit($window, $split))->toBeTrue()
        ->and($split->positions())->toHaveCount(1);
    $split->setPositions([0.5]);
    expect(settledSplit($window, $split))->toBeTrue();
    expect(abs($split->positions()[0] - 0.5))->toBeLessThanOrEqual(splitTolerance($split))
        ->and($split->native()->sizes()[1])->toBe(0);

    $split->view('b')->show();
    expect(settledSplit($window, $split))->toBeTrue()
        ->and($split->positions())->toHaveCount(2)
        ->and(viewMail(session()))->toBe([]);
});

it('posts nothing when a window resize moves the dividers', function (): void {
    [$window, $split] = threePanes();
    $window->present();
    expect(settledSplit($window, $split))->toBeTrue();
    $split->setPositions([0.25, 0.6]);
    expect(settledSplit($window, $split))->toBeTrue();
    viewMail(session());

    $window->native()->resize(900, 400);
    expect(splitPumpUntil(fn (): bool => splitLength($split) > 600.0))->toBeTrue()
        ->and(settledSplit($window, $split))->toBeTrue()
        ->and(viewMail(session()))->toBe([]);
});

it('posts one SplitMoved when a user drag of a handle ends, and none for a still drag or a click on a pane', function (): void {
    [$window, $split] = threePanes();
    $window->present();
    expect(settledSplit($window, $split))->toBeTrue();
    $split->setPositions([0.25, 0.6]);
    expect(settledSplit($window, $split))->toBeTrue();
    viewMail(session());

    $handle = $split->native()->handle(1);
    $x = $handle->width() / 2;
    $y = $handle->height() / 2;
    splitMouse($handle, Type::MOUSE_BUTTON_PRESS, $x, $y);
    splitMouse($handle, Type::MOUSE_MOVE, $x + 50, $y);
    expect(viewMail(session()))->toBe([]);   // nothing while the drag lasts
    splitMouse($handle, Type::MOUSE_BUTTON_RELEASE, $x + 50, $y);
    pumpFor(0.1);
    $mail = viewMail(session());

    expect($mail)->toHaveCount(1)
        ->and($mail[0])->toBeInstanceOf(SplitMoved::class)
        ->and($mail[0]->name())->toBe('view.split-moved.main.m.panes')
        ->and($mail[0]->positions)->toBe($split->positions())
        ->and($split->positions()[0])->toBeGreaterThan(0.25 + 40 / 600);

    // A press that moves without changing the position: Qt still emits splitterMoved.
    splitMouse($handle, Type::MOUSE_BUTTON_PRESS, $x, $y);
    splitMouse($handle, Type::MOUSE_MOVE, $x, $y);
    splitMouse($handle, Type::MOUSE_BUTTON_RELEASE, $x, $y);
    pumpFor(0.1);
    expect(viewMail(session()))->toBe([]);

    $pane = $split->view('a')->native();
    splitMouse($pane, Type::MOUSE_BUTTON_PRESS, 10, 10);
    splitMouse($pane, Type::MOUSE_BUTTON_RELEASE, 10, 10);
    pumpFor(0.1);
    expect(viewMail(session()))->toBe([]);
});

it('titles, selects and removes pages without mail, and posts TabSelected for a user switch', function (): void {
    $window = driver()->open('main', 400, 300);
    $tabs = $window->column('m')->tabs('sessions')->fill();
    $tabs->addPage('one', 'left.txt ↔ right.txt')->label('l1', 'One');
    $tabs->addPage('two', 'Folders')->label('l2', 'Two');
    $tabs->addPage('three', 'Three');
    $window->present();
    pumpFor(0.2);

    /** @var QTabWidget $native */
    $native = $tabs->native();
    expect($tabs->pages())->toBe(['one', 'two', 'three'])
        ->and($tabs->selected())->toBe('one')
        ->and($native->count())->toBe(3);

    $tabs->setTitle('two', 'Folders *')->select('two');
    $two = $tabs->view('two')->native();
    expect($native->tabText($native->indexOf($two)))->toBe('Folders *')
        ->and($tabs->selected())->toBe('two')
        ->and($native->currentIndex())->toBe($native->indexOf($two));

    $tabs->view('two')->remove();
    pumpFor(0.1);
    expect($tabs->pages())->toBe(['one', 'three'])
        ->and($native->count())->toBe(2)
        ->and($tabs->selected())->not->toBeNull()
        ->and(viewMail(session()))->toBe([]);

    $other = $tabs->selected() === 'one' ? 'three' : 'one';
    $native->setCurrentIndex($native->indexOf($tabs->view($other)->native()));
    $mail = viewMail(session());
    expect($mail)->toHaveCount(1)
        ->and($mail[0])->toBeInstanceOf(TabSelected::class)
        ->and($mail[0]->page)->toBe($other)
        ->and($mail[0]->name())->toBe('view.tab-selected.main.m.sessions')
        ->and($tabs->selected())->toBe($other);
});

it('inserts a tab before the one at an index, and posts no TabSelected', function (): void {
    $window = driver()->open('main', 400, 300);
    $tabs = $window->column('m')->tabs('tabs');
    $tabs->addPage('one', 'One');
    $tabs->addPage('two', 'Two');
    takeMail(session());

    $tabs->addPage('first', 'First', 0);
    $tabs->addPage('middle', 'Middle', 2);

    $labels = array_map(fn (int $i): string => $tabs->native()->tabText($i), range(0, $tabs->native()->count() - 1));
    expect($labels)->toBe(['First', 'One', 'Middle', 'Two'])
        ->and($tabs->pages())->toBe(['first', 'one', 'middle', 'two'])
        ->and(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof TabSelected))->toBe([]);
    $window->close();
});

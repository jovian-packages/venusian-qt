<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Primitives\QtColumn;
use Jovian\Toolkits\Qt\Primitives\QtFixed;
use Jovian\Toolkits\Qt\Primitives\QtGrid;
use Jovian\Toolkits\Qt\Primitives\QtRow;
use Jovian\Toolkits\Qt\Primitives\QtScrollView;
use Surface\Contracts\Windows\Mail\View\ViewResized;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\Mail\WindowResized;
use Surface\Contracts\Windows\Primitives\Align;
use Surface\Contracts\Windows\WindowException;
use Surface\NutsAndBolts\Color;

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

it('mounts a column as the window content and lays children out natively', function (): void {
    $window = driver()->open('main', 400, 300);
    $main = $window->column('main', spacing: 8, padding: 10);
    $title = $main->column('title')->minSize(60, 20)->align(Align::CENTER);
    $body = $main->row('body', spacing: 4);
    $left = $body->column('left')->minSize(20, 20)->fill(vertical: false);
    $window->present();
    pumpFor(0.2);

    expect($main)->toBeInstanceOf(QtColumn::class)
        ->and($body)->toBeInstanceOf(QtRow::class)
        ->and($main->native())->toBeInstanceOf(QWidget::class)
        ->and($main->native()->layout())->toBeInstanceOf(QVBoxLayout::class)
        ->and($body->native()->layout())->toBeInstanceOf(QHBoxLayout::class)
        ->and($window->centralWidget()->layout()->indexOf($main->native()))->toBe(0)
        ->and($main->native()->layout()->indexOf($title->native()))->toBe(0)
        ->and($main->native()->layout()->indexOf($body->native()))->toBe(1)
        ->and($main->native()->layout()->spacing())->toBe(8)
        ->and($window->size())->toBe([400, 300])
        ->and($main->size())->toBe([400, 300])
        ->and($left->size()[0])->toBe(380)
        ->and($title->size()[0])->toBeGreaterThanOrEqual(60)
        ->and($title->size()[0])->toBeLessThan(200)
        ->and($window->content())->toBe($main)
        ->and($window->view('main.body.left'))->toBe($left)
        ->and($window->uuid($left->uuid()))->toBe($left);
});

it('reorders children and drops a removed one from the layout and the lookups', function (): void {
    $window = driver()->open('main', 300, 200);
    $main = $window->column('m');
    $a = $main->column('a');
    $b = $main->column('b');
    $c = $main->column('c');
    $layout = $main->native()->layout();
    $c->moveBefore($a);

    expect([$layout->indexOf($c->native()), $layout->indexOf($a->native()), $layout->indexOf($b->native())])->toBe([0, 1, 2]);

    $a->moveTo(2);
    expect($layout->itemAtWidget(2))->toBe($a->native());

    $native = $b->native();
    $b->remove();
    pumpFor(0.05);

    // Two children and the stack's trailing stretch spacer.
    expect($layout->count())->toBe(3)
        ->and($layout->itemAtWidget(2))->toBeNull()
        ->and($main->children())->toBe([$c, $a])
        ->and($window->view('m.b'))->toBeNull()
        ->and($window->uuid($b->uuid()))->toBeNull()
        ->and(fn () => $native->isVisible())->toThrow(QtException::class, 'deleted');
});

it('fills grid cells and absolute frames', function (): void {
    $window = driver()->open('main', 400, 300);
    $grid = $window->grid('g', spacing: 2);
    $a = $grid->at(0, 0, 1, 2)->column('a');
    $b = $grid->at(1, 1)->column('b')->minSize(10, 10);
    $fixed = $grid->at(2, 0)->fixed('f');
    $c = $fixed->at(10, 20, 30, 40)->column('c');
    $window->present();
    pumpFor(0.2);
    $layout = $grid->native()->layout();

    expect($grid)->toBeInstanceOf(QtGrid::class)
        ->and($fixed)->toBeInstanceOf(QtFixed::class)
        ->and($layout)->toBeInstanceOf(QGridLayout::class)
        ->and($layout->itemAtPosition(0, 0))->toBe($a->native())
        ->and($layout->itemAtPosition(0, 1))->toBe($a->native())
        ->and($layout->itemAtPosition(1, 1))->toBe($b->native())
        ->and($fixed->native()->layout())->toBeNull()
        ->and($c->native()->parentWidget())->toBe($fixed->native())
        ->and($c->native()->geometry())->toBe(['x' => 10, 'y' => 20, 'width' => 30, 'height' => 40])
        ->and($c->native()->isVisible())->toBeTrue()
        ->and($c->size())->toBe([30, 40]);

    $fixed->move($c, 15, 25)->resize($c, 50, 60);
    $late = $fixed->at(0, 0, 5, 5)->column('late');
    $b->align(Align::END, Align::END);
    pumpFor(0.1);

    expect($c->native()->pos())->toBe([15, 25])
        ->and($c->size())->toBe([50, 60])
        ->and($late->native()->isVisible())->toBeTrue()
        ->and($layout->itemAtPosition(1, 1))->toBe($b->native())
        ->and($b->size())->toBe([10, 10]);
});

it('scrolls exactly one container', function (): void {
    $window = driver()->open('main', 200, 150);
    $scroll = $window->column('m')->scrollView('s');
    $inner = $scroll->column('inner')->minSize(10, 600);
    $scroll->setScrollbars(false, true);
    $window->present();
    pumpFor(0.2);

    expect($scroll)->toBeInstanceOf(QtScrollView::class)
        ->and($scroll->native())->toBeInstanceOf(QScrollArea::class)
        ->and($scroll->native()->widget())->toBe($inner->native())
        ->and($inner->size()[1])->toBe(600)
        ->and($inner->size()[0])->toBeLessThanOrEqual(200);
});

it('writes visibility, enabled state, background and minimum size to the native', function (): void {
    $window = driver()->open('main', 300, 200);
    $box = $window->column('m')->column('box');
    $window->present();
    pumpFor(0.1);

    expect($box->native()->isVisible())->toBeTrue();

    $box->hide()->disable()->setBackground(new Color(1.0, 0.0, 0.0))->minSize(40, 30);
    expect($box->native()->isVisible())->toBeFalse()
        ->and($box->native()->isEnabled())->toBeFalse()
        ->and($box->native()->styleSheet())->toContain('background-color: '.(new Color(1.0, 0.0, 0.0))->toCss())
        ->and($box->native()->testAttribute(Qt\WidgetAttribute::STYLED_BACKGROUND))->toBeTrue()
        ->and($box->native()->minimumWidth())->toBe(40)
        ->and($box->native()->minimumHeight())->toBe(30);

    $box->show()->enable()->setBackground(null);
    expect($box->native()->isVisible())->toBeTrue()
        ->and($box->native()->isEnabled())->toBeTrue()
        ->and($box->native()->styleSheet())->toBe('');
});

it('posts WindowResized once per pump with the last size', function (): void {
    $window = driver()->open('main', 400, 300)->present();
    pumpFor(0.2);
    takeMail(session());
    $window->native()->resize(420, 300);
    $window->native()->resize(440, 310);
    pumpFor(0.2);

    $resized = array_values(array_filter(takeMail(session()), fn (object $m): bool => $m instanceof WindowResized));
    expect($resized)->toEqual([new WindowResized('main', 440, 310)])
        ->and($window->size())->toBe([440, 310]);
});

it('posts ViewResized only for watched views, coalesced, and stops when unwatched', function (): void {
    $window = driver()->open('main', 400, 300);
    $main = $window->column('m');
    $watched = $main->column('w')->watchSize();
    $silent = $main->column('s');
    $window->present();
    pumpFor(0.2);
    takeMail(session());
    $window->native()->resize(500, 300);
    $window->native()->resize(520, 300);
    pumpFor(0.2);

    $resized = array_values(array_filter(takeMail(session()), fn (object $m): bool => $m instanceof ViewResized));
    expect($resized)->toHaveCount(1)
        ->and($resized[0])->toEqual(new ViewResized('main', 'm.w', $watched->uuid(), ...$watched->size()))
        ->and($watched->size()[0])->toBe(520)
        ->and($silent->size()[0])->toBe(520);

    $watched->watchSize(false);
    $window->native()->resize(540, 300);
    pumpFor(0.2);
    expect(array_filter(takeMail(session()), fn (object $m): bool => $m instanceof ViewResized))->toBe([]);
});

it('removes the content tree when the window closes and drops its pending resize mail', function (): void {
    $window = driver()->open('main', 400, 300);
    $inner = $window->column('main')->row('a')->watchSize();
    $window->present();
    pumpFor(0.2);
    takeMail(session());
    $window->native()->resize(500, 300);
    // A raw pump delivers the resize events without flushing: both resize mails are pending.
    session()->pump(50_000_000);
    expect(pendingLatest(session()))->toContain('window.resized.main', 'view.resized.main.main.a');
    $window->close();
    pumpFor(0.05);
    $mail = takeMail(session());

    expect($inner->isRemoved())->toBeTrue()
        ->and(array_filter($mail, fn (object $m): bool => $m instanceof WindowResized || $m instanceof ViewResized))->toBe([])
        ->and($mail)->toContainEqual(new WindowClosed('main'))
        ->and(fn () => $window->view('main.a'))->toThrow(WindowException::class, 'closed')
        ->and(fn () => $window->content())->toThrow(WindowException::class, 'closed');
});

it('throws for the primitives Qt has no widget for', function (): void {
    $main = driver()->open('main', 300, 200)->column('m');

    expect(fn () => $main->toggle('t'))->toThrow(WindowException::class, 'TKToggle is not available on qt.')
        ->and(fn () => $main->spinner('s'))->toThrow(WindowException::class, 'TKSpinner is not available on qt.')
        ->and($main->children())->toBe([]);
});

it('gives spare space only to children that fill, along the stack', function (): void {
    $window = driver()->open('main', 300, 200);
    $main = $window->column('m');
    $label = $main->label('l', 'L');
    $button = $main->button('b', 'B');
    $window->present();
    pumpFor(0.2);
    $hint = $label->native()->sizeHint()[1];

    expect($label->size()[1])->toBe($hint)
        ->and($button->native()->pos()[1])->toBeLessThan(100);

    $label->fill();
    pumpFor(0.1);
    expect($label->size()[1])->toBeGreaterThan(100)
        ->and($main->native()->layout()->stretch($main->native()->layout()->count() - 1))->toBe(0);

    $label->fill(false, false);
    pumpFor(0.1);
    expect($label->size()[1])->toBe($hint);

    $row = $main->row('r');
    $a = $row->label('a', 'A');
    $b = $row->label('b', 'B');
    $b->moveBefore($a);
    pumpFor(0.1);
    expect($a->size()[0])->toBe($a->native()->sizeHint()[0])
        ->and($row->native()->layout()->indexOf($b->native()))->toBe(0);

    $a->fill(true, false);
    pumpFor(0.1);
    expect($a->size()[0])->toBeGreaterThan(200)
        ->and($b->size()[0])->toBe($b->native()->sizeHint()[0]);
});

it('gives spare grid space only to the rows and columns of children that fill', function (): void {
    $window = driver()->open('main', 400, 300);
    $grid = $window->grid('g');
    $a = $grid->at(0, 0)->label('a', 'A');
    $b = $grid->at(1, 1)->label('b', 'B');
    $window->present();
    pumpFor(0.2);

    expect($a->size())->toBe($a->native()->sizeHint())
        ->and($b->size())->toBe($b->native()->sizeHint());

    $a->fill();
    pumpFor(0.1);
    expect($a->size()[0])->toBeGreaterThan(300)
        ->and($a->size()[1])->toBeGreaterThan(200)
        ->and($b->size())->toBe($b->native()->sizeHint())
        ->and($grid->native()->layout()->rowStretch(0))->toBe(1)
        ->and($grid->native()->layout()->columnStretch(1))->toBe(0);
});

it('keeps a fixed container as large as its frames beside a filling sibling', function (): void {
    $window = driver()->open('main', 300, 200);
    $row = $window->row('r');
    $fixed = $row->fixed('f');
    $fixed->at(10, 20, 30, 40)->column('c');
    $row->textInput('t')->fill();
    $window->present();
    pumpFor(0.2);

    expect($fixed->size()[0])->toBeGreaterThanOrEqual(40)
        ->and($fixed->native()->minimumHeight())->toBe(60);

    $fixed->move($fixed->view('c'), 100, 0);
    pumpFor(0.1);
    expect($fixed->size()[0])->toBeGreaterThanOrEqual(130)
        ->and($fixed->native()->minimumWidth())->toBe(130);

    $fixed->minSize(200, 10);
    pumpFor(0.1);
    expect($fixed->native()->minimumWidth())->toBe(200)
        ->and($fixed->native()->minimumHeight())->toBe(40);
});


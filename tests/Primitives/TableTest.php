<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Primitives\QtTable;
use Surface\Contracts\Windows\Mail\View\RowSelected;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\WindowException;

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

it('shows rows, normalises missing cells, and posts RowSelected only for native selection', function (): void {
    $window = driver()->open('main', 400, 300);
    $table = $window->column('m')->table('t', [new TableColumn('title', 'Title'), new TableColumn('year', 'Year')], [['title' => 'a', 'year' => '1'], ['title' => 'b']]);
    $window->present();
    pumpFor(0.2);
    takeMail(session());
    $native = $table->native();

    expect($table)->toBeInstanceOf(QtTable::class)
        ->and($native)->toBeInstanceOf(QTableWidget::class)
        ->and([$native->rowCount(), $native->columnCount()])->toBe([2, 2])
        ->and($native->horizontalHeaderItem(0)->text())->toBe('Title')
        ->and($native->horizontalHeaderItem(1)->text())->toBe('Year')
        ->and($native->item(0, 1)->text())->toBe('1')
        ->and($native->item(1, 1)->text())->toBe('');

    $table->selectRow(1);
    pumpFor(0.1);
    expect($table->rows()[1])->toBe(['title' => 'b', 'year' => ''])
        ->and($table->selectedRow())->toBe(1)
        ->and($native->selectedItems()[0]->row())->toBe(1)
        ->and(takeMail(session()))->toEqual([])
        ->and(fn () => $table->selectRow(5))->toThrow(WindowException::class);

    $native->selectRow(0);
    pumpFor(0.1);
    expect(takeMail(session()))->toEqual([new RowSelected('main', 'm.t', $table->uuid(), 0, ['title' => 'a', 'year' => '1'])])
        ->and($table->selectedRow())->toBe(0);

    $native->clearSelection();
    expect(takeMail(session()))->toEqual([new RowSelected('main', 'm.t', $table->uuid(), null, null)])
        ->and($table->selectedRow())->toBeNull();

    $table->appendRow(['title' => 'c', 'year' => 3]);
    expect($native->rowCount())->toBe(3)
        ->and($native->item(2, 1)->text())->toBe('3');

    $table->selectRow(2);
    $table->setRows([['title' => 'z']]);
    expect($native->rowCount())->toBe(1)
        ->and($native->item(0, 0)->text())->toBe('z')
        ->and($native->selectedItems())->toBe([])
        ->and($table->selectedRow())->toBeNull()
        ->and(takeMail(session()))->toBe([]);

    $table->selectRow(0);
    $table->appendRow(['title' => 'y']);
    expect($native->selectedItems()[0]->row())->toBe(0)
        ->and(takeMail(session()))->toBe([]);
});

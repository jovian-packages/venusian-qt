<?php

namespace Jovian\Toolkits\Qt\UI;

use QObject;
use QTableWidget;
use WeakReference;
use QTableWidgetItem;
use QAbstractItemView;
use QAbstractItemView\SelectionMode;
use QAbstractItemView\SelectionBehavior;
use Surface\Toolkits\UI\TKTable;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * A QTableWidget that selects whole rows, one at a time, and edits none.
 */
class QtTable extends TKTable
{
    use QtNative;

    protected function bootstrapNative(): void
    {
        $table = new QTableWidget(0, count($this->columns()));
        $table->setSelectionBehavior(SelectionBehavior::SELECT_ROWS);
        $table->setSelectionMode(SelectionMode::SINGLE_SELECTION);
        $table->setEditTriggers(QAbstractItemView::NO_EDIT_TRIGGERS);
        $table->setHorizontalHeaderLabels(array_map(static fn ($column): string => $column->label, $this->columns()));
        $this->adoptNative($table);
        $this->write(fn () => $this->applyRows($this->cellRows()));

        $self = WeakReference::create($this);
        QObject::connect($table, 'itemSelectionChanged()', static function () use ($self): void {
            $self->get()?->selectionChanged();
        });
    }

    protected function applyRows(array $cells): void
    {
        $table = $this->tableNative();
        $table->clearContents();
        $table->setRowCount(count($cells));
        foreach ($cells as $row => $values) {
            foreach ($values as $column => $text) {
                $table->setItem($row, $column, new QTableWidgetItem($text));
            }
        }
    }

    protected function applySelectedRow(?int $row): void
    {
        is_null($row) ? $this->tableNative()->clearSelection() : $this->tableNative()->selectRow($row);
    }

    private function selectionChanged(): void
    {
        if ($this->isRemoved() || $this->isWriting()) {
            return;
        }
        $items = $this->tableNative()->selectedItems();
        $this->choseRow($items === [] ? null : $items[0]->row());
    }

    private function tableNative(): QTableWidget
    {
        /** @var QTableWidget */
        return $this->native();
    }
}

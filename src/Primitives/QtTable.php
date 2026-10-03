<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QAbstractItemView;
use QAbstractItemView\SelectionBehavior;
use QAbstractItemView\SelectionMode;
use QObject;
use QSizePolicy\Policy;
use QTableWidget;
use QTableWidgetItem;
use Surface\Contracts\Windows\Mail\View\RowSelected;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKTable;

/**
 * A read-only QTableWidget: whole rows, one at a time. The selected row is read from the
 * selected items, so a deselect (Ctrl-click) reports null where currentRow() would not.
 */
class QtTable extends TKTable implements QtNative
{
    use QtPrimitive;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, array $columns, array $rows)
    {
        parent::__construct($name, $window, $parent, $placement, $columns, $rows);
        $table = $this->adoptNative(new QTableWidget(0, count($this->columns)));
        $table->setSelectionBehavior(SelectionBehavior::SELECT_ROWS);
        $table->setSelectionMode(SelectionMode::SINGLE_SELECTION);
        $table->setEditTriggers(QAbstractItemView::NO_EDIT_TRIGGERS);
        $table->setHorizontalHeaderLabels(array_map(fn (TableColumn $column): string => $column->label, $this->columns));
        $this->applyRows($this->cellRows());

        QObject::connect($table, 'itemSelectionChanged()', function (): void {
            if (! $this->handling()) {
                return;
            }
            $items = $this->native()->selectedItems();
            $row = $items === [] ? null : $items[0]->row();
            $this->nativeRowSelected($row);
            $this->session()->post(new RowSelected($this->window->name(), $this->path(), $this->uuid, $row, is_null($row) ? null : $this->rows[$row]));
        });
    }

    public function native(): QTableWidget
    {
        return $this->native;
    }

    protected function naturalPolicy(): array
    {
        return [Policy::EXPANDING, Policy::EXPANDING];
    }

    protected function applyRows(array $cells): void
    {
        $this->applying(function () use ($cells): void {
            $table = $this->native();
            $table->clearContents();
            $table->setRowCount(count($cells));
            foreach ($cells as $row => $values) {
                foreach ($values as $column => $text) {
                    $table->setItem($row, $column, new QTableWidgetItem($text));
                }
            }
        });
    }

    protected function applySelectedRow(?int $row): void
    {
        $this->applying(function () use ($row): void {
            if (is_null($row)) {
                $this->native()->clearSelection();
            } else {
                $this->native()->selectRow($row);
            }
        });
    }
}

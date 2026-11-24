<?php

namespace Jovian\Toolkits\Qt\UI;

use QWidget;
use QGridLayout;
use Surface\Toolkits\UI\TKGrid;
use Surface\Toolkits\UI\TKPrimitive;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;
use Jovian\Toolkits\Qt\UI\Concerns\PlacesInSlots;

/**
 * A QWidget with a QGridLayout. An empty row and column past the children take the spare
 * room while nothing fills.
 */
class QtGrid extends TKGrid
{
    use QtNative;
    use PlacesInSlots;

    private ?QGridLayout $cells_layout = null;

    /** Rows and columns a stretch was set on, to clear. */
    private int $rows = 0;

    private int $columns = 0;

    protected function bootstrapNative(): void
    {
        $widget = new QWidget();
        $this->cells_layout = new QGridLayout($widget);
        $this->cells_layout->setSpacing($this->spacing());
        $this->cells_layout->setContentsMargins($this->padding, $this->padding, $this->padding, $this->padding);
        $this->adoptNative($widget);
        $this->restretch();
    }

    protected function attachNative(TKPrimitive $child): void
    {
        $this->put($child);
        $this->restretch();
    }

    protected function detachNative(TKPrimitive $child): void
    {
        $this->cells_layout->removeWidget($child->native());
        $this->restretch();
    }

    protected function applyCells(): void
    {
        foreach ($this->children() as $child) {
            $this->cells_layout->removeWidget($child->native());
        }
        foreach ($this->children() as $child) {
            $this->put($child);
        }
        $this->restretch();
    }

    protected function applySpacing(int $spacing): void
    {
        $this->cells_layout->setSpacing($spacing);
    }

    /** An item's alignment is given when it is added: the child goes back into its own cell. */
    protected function childLayoutChanged(TKPrimitive $child): void
    {
        $this->cells_layout->removeWidget($child->native());
        $this->put($child);
        $this->restretch();
    }

    protected function childVisibilityChanged(TKPrimitive $child): void
    {
        $this->restretch();
    }

    private function put(TKPrimitive $child): void
    {
        $cell = $this->cellOf($child);
        $this->cells_layout->addWidget($child->native(), $cell->row, $cell->column, $cell->rowSpan, $cell->columnSpan, self::alignmentOf($child));
    }

    private function restretch(): void
    {
        [$rows, $columns] = [[], []];
        foreach ($this->children() as $child) {
            if (! $child->isVisible()) {
                continue;
            }
            $cell = $this->cellOf($child);
            [$across, $down] = [$child->expands(true), $child->expands(false)];
            for ($row = $cell->row; $row < $cell->row + $cell->rowSpan; $row++) {
                $rows[$row] = ($rows[$row] ?? false) || $down;
            }
            for ($column = $cell->column; $column < $cell->column + $cell->columnSpan; $column++) {
                $columns[$column] = ($columns[$column] ?? false) || $across;
            }
        }

        $this->rows = self::spread($rows, $this->rows, $this->cells_layout->setRowStretch(...));
        $this->columns = self::spread($columns, $this->columns, $this->cells_layout->setColumnStretch(...));
        $this->tellExpansion();
    }

    /**
     * @param array<int, bool> $filling by row or column
     * @return int how many were set
     */
    private static function spread(array $filling, int $before, \Closure $stretch): int
    {
        $spare = $filling === [] ? 0 : max(array_keys($filling)) + 1;
        $count = max($before, $spare + 1);
        $none = ! in_array(true, $filling, true);
        for ($line = 0; $line < $count; $line++) {
            $stretch($line, ($filling[$line] ?? false) || ($none && $line === $spare) ? 1 : 0);
        }

        return $count;
    }
}

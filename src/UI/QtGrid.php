<?php

namespace Jovian\Toolkits\Qt\UI;

use QWidget;
use QGridLayout;
use QHBoxLayout;
use Surface\Toolkits\UI\TKGrid;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Contracts\Toolkits\UI\Insets;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;
use Jovian\Toolkits\Qt\UI\Concerns\QtHeldMargin;
use Jovian\Toolkits\Qt\UI\Concerns\PlacesInSlots;

/**
 * A QWidget with a QGridLayout. An empty row and column past the children take the spare
 * room while nothing fills. A child with a margin is in its cell in a holder whose contents
 * margins are that margin (QtHeldMargin).
 */
class QtGrid extends TKGrid
{
    use QtNative;
    use PlacesInSlots;
    use QtHeldMargin;

    /** @var array<string, QHBoxLayout> the holder of each child with a margin, by its uuid */
    private array $holders = [];

    private ?QGridLayout $cells_layout = null;

    /** Rows and columns a stretch was set on, to clear. */
    private int $rows = 0;

    private int $columns = 0;

    protected function bootstrapNative(): void
    {
        $widget = new QWidget();
        $this->cells_layout = new QGridLayout($widget);
        $this->cells_layout->setSpacing($this->spacing());
        $this->applyPadding($this->padding());
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
        $this->take($child);
        $this->restretch();
    }

    protected function applyCells(): void
    {
        foreach ($this->children() as $child) {
            $this->take($child);
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

    /** The layout's margins, each side its own. */
    protected function applyPadding(Insets $padding): void
    {
        $this->cells_layout->setContentsMargins($padding->left, $padding->top, $padding->right, $padding->bottom);
    }

    /** An item's alignment is given when it is added: the child goes back into its own cell. */
    protected function childLayoutChanged(TKPrimitive $child): void
    {
        $this->take($child);
        $this->put($child);
        $this->restretch();
    }

    /**
     * A hidden child's margin takes no room.
     */
    protected function childVisibilityChanged(TKPrimitive $child): void
    {
        $holder = $this->holders[$child->uuid()] ?? null;
        if (! is_null($holder)) {
            self::holdMargin($holder, $child);
        }
        $this->restretch();
    }

    /**
     * Into its cell: itself, or in a holder of its margin that fills the cell.
     */
    private function put(TKPrimitive $child): void
    {
        $cell = $this->cellOf($child);
        if (self::heldMarginOf($child)->isZero()) {
            $this->cells_layout->addWidget($child->native(), $cell->row, $cell->column, $cell->rowSpan, $cell->columnSpan, self::alignmentOf($child));

            return;
        }

        $holder = self::marginHolder($child, self::alignmentOf($child));
        $this->cells_layout->addLayout($holder, $cell->row, $cell->column, $cell->rowSpan, $cell->columnSpan, 0);
        $this->holders[$child->uuid()] = $holder;
    }

    /**
     * Out of its cell, and out of its holder when it has one (the holder goes with it).
     */
    private function take(TKPrimitive $child): void
    {
        $holder = $this->holders[$child->uuid()] ?? null;
        if (is_null($holder)) {
            $this->cells_layout->removeWidget($child->native());

            return;
        }

        $this->cells_layout->removeItem($holder);
        $holder->removeWidget($child->native());
        unset($this->holders[$child->uuid()]);
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

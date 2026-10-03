<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtContainer;
use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QGridLayout;
use QWidget;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\TKPrimitive as Child;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKGrid;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * Spare space goes to the rows and columns holding a child that fills that axis (stretch 1);
 * when no child fills an axis, an empty row or column past the last takes it, so unfilled
 * cells keep their size hints.
 */
class QtGrid extends TKGrid implements QtContainer
{
    use QtPrimitive;

    protected QGridLayout $layout;

    /**
     * The empty row and column past the children that hold the spare space when nothing fills.
     */
    protected int $spare_row = -1;

    protected int $spare_column = -1;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, int $spacing, int $padding)
    {
        parent::__construct($name, $window, $parent, $placement, $spacing, $padding);
        $this->layout = new QGridLayout($this->adoptNative(new QWidget()));
        $this->layout->setSpacing($spacing);
        $this->layout->setContentsMargins($padding, $padding, $padding, $padding);
        $this->restretch();
    }

    /**
     * A grid item's alignment is fixed when it is added: the child goes back into its own cell.
     * @param Child&QtNative $child
     * @return void
     */
    public function placeChild(Child&QtNative $child): void
    {
        $this->layout->removeWidget($child->native());
        $this->insertNative($child);
    }

    public function forgetChild(Child $child): void
    {
        parent::forgetChild($child);
        $this->whileNativeAlive(fn () => $this->restretch());
    }

    protected function insertNative(Child $child): void
    {
        $cell = $child->placement();
        $this->layout->addWidget(
            self::nativeOf($child),
            $cell->row,
            $cell->column,
            $cell->rowSpan,
            $cell->columnSpan,
            $child instanceof QtNative ? $child->qtAlignment() : 0,
        );
        $this->restretch();
    }

    protected function restretch(): void
    {
        $rows = [];
        $columns = [];
        foreach ($this->children as $child) {
            [$fills_horizontal, $fills_vertical] = $child instanceof QtNative ? $child->fills() : [false, false];
            $cell = $child->placement();
            for ($row = $cell->row; $row < $cell->row + $cell->rowSpan; $row++) {
                $rows[$row] = ($rows[$row] ?? false) || $fills_vertical;
            }
            for ($column = $cell->column; $column < $cell->column + $cell->columnSpan; $column++) {
                $columns[$column] = ($columns[$column] ?? false) || $fills_horizontal;
            }
        }

        $this->spare_row = $this->spread($rows, $this->spare_row, $this->layout->setRowStretch(...));
        $this->spare_column = $this->spread($columns, $this->spare_column, $this->layout->setColumnStretch(...));
    }

    /**
     * @param array<int, bool> $filling line index => whether a child in it fills
     * @param int $old_spare the previous spare line, -1 for none
     * @param \Closure(int, int): void $stretch
     * @return int the new spare line
     */
    protected function spread(array $filling, int $old_spare, \Closure $stretch): int
    {
        if ($old_spare >= 0) {
            $stretch($old_spare, 0);
        }
        foreach ($filling as $line => $fills) {
            $stretch($line, $fills ? 1 : 0);
        }
        $spare = $filling === [] ? 0 : max(array_keys($filling)) + 1;
        $stretch($spare, in_array(true, $filling, true) ? 0 : 1);

        return $spare;
    }

    protected function applySpacing(int $spacing): void
    {
        $this->layout->setSpacing($spacing);
    }
}

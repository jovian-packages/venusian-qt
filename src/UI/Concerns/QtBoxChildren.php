<?php

namespace Jovian\Toolkits\Qt\UI\Concerns;

use QWidget;
use QBoxLayout;
use QHBoxLayout;
use QVBoxLayout;
use Qt\AlignmentFlag;
use QSizePolicy\Policy;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Contracts\Toolkits\UI\Align;
use Surface\Contracts\Toolkits\UI\Insets;

/**
 * What a Qt column and row share: a QWidget with a box layout. Padding is the layout's
 * margins, each side its own, inside the widget's own background. The layout ends with a stretch, so children
 * keep their size at the start; it gives way (stretch 0) once a child fills along the axis.
 * A child's fill and alignment live in this layout (its stretch and alignment). Homogeneous:
 * every child stretch 1 and its size policy along the axis IGNORED.
 *
 * A column places a child narrower than itself (aligned across, or with a maximum width) in a
 * row of its own: a QHBoxLayout holding the child and stretches. A QVBoxLayout asks every
 * child for its height at the column's whole width (QBoxLayoutPrivate::calcHfw) and only then
 * places an aligned or capped child narrower (QWidgetItem::setGeometry), so text that wraps is
 * given too little height and what follows it overlaps it. A row shares its width out first
 * and asks each item for its height at the width it gets, so the column reserves the right
 * height. Left: child, stretch; right: stretch, child; centred: stretch, child, stretch;
 * filling up to a maximum: the child (stretch 1) then a stretch that takes what is left.
 *
 * A child's margin is the contents margins of its row (QtHeldMargin): a child with a margin
 * and no row of its own otherwise, in a column or a row, gets one holding it alone with its
 * alignment.
 */
trait QtBoxChildren
{
    use QtNative;
    use PlacesInSlots;
    use QtHeldMargin;

    private ?QBoxLayout $layout = null;

    /**
     * The row each child placed in one sits in, and how it was built.
     *
     * @var array<string, array{row: QHBoxLayout, shape: string}>
     */
    private array $rows = [];

    abstract protected function newLayout(QWidget $widget): QBoxLayout;

    /**
     * Whether $child fills along this container's axis.
     */
    abstract protected function fillsAlong(TKPrimitive $child): bool;

    protected function bootstrapNative(): void
    {
        $widget = new QWidget();
        $this->layout = $this->newLayout($widget);
        $this->layout->setSpacing($this->spacing());
        $this->applyPadding($this->padding());
        $this->layout->addStretch(1);
        $this->adoptNative($widget);
    }

    /**
     * Just before the trailing stretch.
     */
    protected function attachNative(TKPrimitive $child): void
    {
        $this->place($child, $this->layout->count() - 1);
        if ($this->isHomogeneous()) {
            $child->refreshSizePolicy();
        }
        $this->settleStretch();
    }

    /**
     * Every child the same length along the axis, Qt's own way: each has stretch factor 1
     * and its size policy that way IGNORED, so the layout reads no size hint from it and
     * shares the room by the equal factors; the trailing stretch takes none. Off, each child
     * gets its stretch (from its fill) and its own policy back.
     */
    protected function applyHomogeneous(bool $on): void
    {
        foreach ($this->children() as $child) {
            $this->layout->setStretch($this->slotOf($child), $this->stretchOf($child));
            $child->refreshSizePolicy();
        }
        $this->settleStretch();
    }

    /**
     * Out of the layout; the child's own release takes it off this widget.
     */
    protected function detachNative(TKPrimitive $child): void
    {
        $this->unplace($child);
        $this->settleStretch();
    }

    protected function reorderNative(TKPrimitive $child, int $index): void
    {
        $this->unplace($child);
        $this->place($child, $index);
    }

    protected function applySpacing(int $spacing): void
    {
        $this->layout->setSpacing($spacing);
    }

    /**
     * Filling across with a maximum across, the trailing stretch's preferred cross size is
     * that maximum, so the layout's size hint is, and a parent that centres this column (or
     * row) gives it its room up to the maximum. The stretch still shrinks to nothing (its
     * policy that way is PREFERRED). Along the axis the size hint stays the children's.
     */
    protected function holdCeiling(): void
    {
        $stretch = $this->layout?->itemAt($this->layout->count() - 1)?->spacerItem();
        if (is_null($stretch)) {
            return;
        }

        [$maxWidth, $maxHeight] = $this->maximumSize();
        [$fillsAcross, $fillsDown] = $this->fills();
        if ($this->layout instanceof QVBoxLayout) {
            $across = $fillsAcross && $maxWidth > 0 ? $maxWidth : 0;
            $stretch->changeSize($across, 0, $across > 0 ? Policy::PREFERRED : Policy::MINIMUM, Policy::EXPANDING);
        } else {
            $down = $fillsDown && $maxHeight > 0 ? $maxHeight : 0;
            $stretch->changeSize(0, $down, Policy::EXPANDING, $down > 0 ? Policy::PREFERRED : Policy::MINIMUM);
        }
        $this->layout->invalidate();
    }

    protected function applyPadding(Insets $padding): void
    {
        $this->layout->setContentsMargins($padding->left, $padding->top, $padding->right, $padding->bottom);
    }

    /**
     * A child that comes to need a row (or a different one), or no longer needs one, is put
     * back in the same place the new way. A row kept takes the child's margin as it is now.
     */
    protected function childLayoutChanged(TKPrimitive $child): void
    {
        $held = $this->rows[$child->uuid()]['shape'] ?? null;
        if ($held !== $this->shapeOf($child)) {
            $index = $this->slotOf($child);
            $this->unplace($child);
            $this->place($child, $index);
        } elseif (is_null($held)) {
            $native = $child->native();
            $this->layout->setStretch($this->layout->indexOf($native), $this->stretchOf($child));
            $this->layout->setAlignment($native, self::alignmentOf($child));
        } else {
            $row = $this->rows[$child->uuid()]['row'];
            $this->layout->setStretch($this->slotOf($child), $this->stretchOf($child));
            $row->setAlignment($child->native(), $held === 'margin' ? self::alignmentOf($child) : self::verticalOf($child));
            self::holdMargin($row, $child);
        }
        $this->settleStretch();
    }

    /**
     * A hidden child's fill counts for nothing, nor its margin.
     */
    protected function childVisibilityChanged(TKPrimitive $child): void
    {
        $row = $this->rows[$child->uuid()]['row'] ?? null;
        if (! is_null($row)) {
            self::holdMargin($row, $child);
        }
        $this->settleStretch();
    }

    /**
     * Into the layout at $index: straight in, or in a row of its own (shapeOf()).
     */
    private function place(TKPrimitive $child, int $index): void
    {
        $native = $child->native();
        $shape = $this->shapeOf($child);
        if (is_null($shape)) {
            $this->layout->insertWidget($index, $native, $this->stretchOf($child), self::alignmentOf($child));

            return;
        }

        $row = new QHBoxLayout();
        self::holdMargin($row, $child);
        $row->setSpacing(0);
        $vertical = self::verticalOf($child);
        match ($shape) {
            'start' => $this->fillRow($row, [null, [$native, 0, $vertical], 1]),
            'end' => $this->fillRow($row, [1, [$native, 0, $vertical]]),
            'center' => $this->fillRow($row, [1, [$native, 0, $vertical], 1]),
            'fill' => $this->fillRow($row, [[$native, 1, $vertical], 0]),
            'margin' => $this->fillRow($row, [[$native, 0, self::alignmentOf($child)]]),
        };
        $this->layout->insertLayout($index, $row, $this->stretchOf($child));
        $this->rows[$child->uuid()] = ['row' => $row, 'shape' => $shape];
    }

    /**
     * Each item: a stretch factor (a stretch), or [widget, stretch, alignment]; null is nothing.
     *
     * @param  list<int|array{QWidget, int, int}|null>  $items
     */
    private function fillRow(QHBoxLayout $row, array $items): void
    {
        foreach ($items as $item) {
            if (is_int($item)) {
                $row->addStretch($item);
            } elseif (is_array($item)) {
                $row->addWidget(...$item);
            }
        }
    }

    /**
     * Out of the layout, and out of its row when it has one (the row goes with it).
     */
    private function unplace(TKPrimitive $child): void
    {
        $native = $child->native();
        $held = $this->rows[$child->uuid()] ?? null;
        if (is_null($held)) {
            $this->layout->removeWidget($native);

            return;
        }

        $this->layout->removeItem($held['row']);
        $held['row']->removeWidget($native);
        unset($this->rows[$child->uuid()]);
    }

    /**
     * Where the child sits in this layout: its own item, or its row's.
     */
    private function slotOf(TKPrimitive $child): int
    {
        $row = $this->rows[$child->uuid()]['row'] ?? null;
        if (is_null($row)) {
            return $this->layout->indexOf($child->native());
        }

        for ($i = 0, $n = $this->layout->count(); $i < $n; $i++) {
            if ($this->layout->itemAt($i)?->layout() === $row) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * The row the child is placed in, or null for none: in a column, a child aligned across
     * (start, center, end), or filling across up to a maximum width; else, in a column or a
     * row, a child with a margin this layout keeps ('margin': the child alone with its
     * alignment). A row (its axis across) asks each child at the width it gives, so needs
     * none for alignment.
     */
    private function shapeOf(TKPrimitive $child): ?string
    {
        $across = null;
        if ($this->layout instanceof QVBoxLayout) {
            [$horizontal] = $child->aligned();
            $across = match ($horizontal) {
                Align::START => 'start',
                Align::CENTER => 'center',
                Align::END => 'end',
                Align::FILL => $child->maximumSize()[0] > 0 ? 'fill' : null,
            };
        }

        return $across ?? (self::heldMarginOf($child)->isZero() ? null : 'margin');
    }

    /**
     * The vertical part of the child's alignment: for its place in a row of its own.
     */
    private static function verticalOf(TKPrimitive $child): int
    {
        return self::alignmentOf($child) & AlignmentFlag::VERTICAL_MASK->value;
    }

    private function stretchOf(TKPrimitive $child): int
    {
        return $this->isHomogeneous() || $this->fillsAlong($child) ? 1 : 0;
    }

    /**
     * The trailing stretch takes the spare room unless a child fills along the axis.
     */
    private function settleStretch(): void
    {
        $filling = false;
        foreach ($this->children() as $child) {
            $filling = $filling || ($child->isVisible() && ($this->isHomogeneous() || $this->fillsAlong($child)));
        }

        $this->layout->setStretch($this->layout->count() - 1, $filling ? 0 : 1);
        $this->tellExpansion();
    }
}

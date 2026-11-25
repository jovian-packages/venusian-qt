<?php

namespace Jovian\Toolkits\Qt\UI\Concerns;

use QWidget;
use QBoxLayout;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Contracts\Toolkits\UI\Insets;

/**
 * What a Qt column and row share: a QWidget with a box layout. Padding is the layout's
 * margins, each side its own, inside the widget's own background. The layout ends with a stretch, so children
 * keep their size at the start; it gives way (stretch 0) once a child fills along the axis.
 * A child's fill and alignment live in this layout (its stretch and alignment).
 */
trait QtBoxChildren
{
    use QtNative;
    use PlacesInSlots;

    private ?QBoxLayout $layout = null;

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
        $this->layout->insertWidget($this->layout->count() - 1, $child->native(), $this->stretchOf($child), self::alignmentOf($child));
        $this->settleStretch();
    }

    /**
     * Out of the layout; the child's own release takes it off this widget.
     */
    protected function detachNative(TKPrimitive $child): void
    {
        $this->layout->removeWidget($child->native());
        $this->settleStretch();
    }

    protected function reorderNative(TKPrimitive $child, int $index): void
    {
        $this->layout->removeWidget($child->native());
        $this->layout->insertWidget($index, $child->native(), $this->stretchOf($child), self::alignmentOf($child));
    }

    protected function applySpacing(int $spacing): void
    {
        $this->layout->setSpacing($spacing);
    }

    protected function applyPadding(Insets $padding): void
    {
        $this->layout->setContentsMargins($padding->left, $padding->top, $padding->right, $padding->bottom);
    }

    protected function childLayoutChanged(TKPrimitive $child): void
    {
        $native = $child->native();
        $this->layout->setStretch($this->layout->indexOf($native), $this->stretchOf($child));
        $this->layout->setAlignment($native, self::alignmentOf($child));
        $this->settleStretch();
    }

    /**
     * A hidden child's fill counts for nothing.
     */
    protected function childVisibilityChanged(TKPrimitive $child): void
    {
        $this->settleStretch();
    }

    private function stretchOf(TKPrimitive $child): int
    {
        return $this->fillsAlong($child) ? 1 : 0;
    }

    /**
     * The trailing stretch takes the spare room unless a child fills along the axis.
     */
    private function settleStretch(): void
    {
        $filling = false;
        foreach ($this->children() as $child) {
            $filling = $filling || ($child->isVisible() && $this->fillsAlong($child));
        }

        $this->layout->setStretch($this->layout->count() - 1, $filling ? 0 : 1);
        $this->tellExpansion();
    }
}

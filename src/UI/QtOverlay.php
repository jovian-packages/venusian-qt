<?php

namespace Jovian\Toolkits\Qt\UI;

use QWidget;
use QGridLayout;
use QSizePolicy\Policy;
use Surface\Toolkits\UI\TKOverlay;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Contracts\Toolkits\UI\Insets;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;
use Jovian\Toolkits\Qt\UI\Concerns\PlacesInSlots;

/**
 * A QWidget with a QGridLayout holding every child in its one cell, each with its alignment;
 * padding is the layout's margins. The first child is at the back: the widgets are raised in
 * the children's order. A child that fills both ways has Qt's IGNORED size policy, so it
 * takes the cell's size and gives the overlay none; the others give it the largest of theirs.
 * A child's margin is what the child keeps itself (QtSheetMargin), as in a column.
 */
class QtOverlay extends TKOverlay
{
    use QtNative;
    use PlacesInSlots;

    private ?QGridLayout $stack = null;

    /**
     * Whether $child gives this overlay its natural size.
     */
    public function sizedBy(TKPrimitive $child): bool
    {
        return $this->sizesOverlay($child);
    }

    protected function bootstrapNative(): void
    {
        $widget = new QWidget();
        $this->stack = new QGridLayout($widget);
        $this->stack->setSpacing(0);
        $this->adoptNative($widget);
        $this->applyPadding($this->padding());
    }

    protected function attachNative(TKPrimitive $child): void
    {
        $this->put($child);
        $this->sizeChild($child);
        $this->restack();
        $this->tellExpansion();
    }

    /**
     * Out of the layout; the child's own release takes it off this widget.
     */
    protected function detachNative(TKPrimitive $child): void
    {
        $this->stack->removeWidget($child->native());
        $this->tellExpansion();
    }

    protected function reorderNative(TKPrimitive $child, int $index): void
    {
        $this->restack();
    }

    protected function applyPadding(Insets $padding): void
    {
        $this->stack->setContentsMargins($padding->left, $padding->top, $padding->right, $padding->bottom);
    }

    /**
     * An item's alignment is given when it is added: the child goes back into the cell. Its
     * fill decides whether Qt reads its size hint; the child sets its policy again after this
     * (applyFill), the same way.
     */
    protected function childLayoutChanged(TKPrimitive $child): void
    {
        $this->stack->removeWidget($child->native());
        $this->put($child);
        $this->sizeChild($child);
        $this->restack();
        $this->tellExpansion();
    }

    protected function childVisibilityChanged(TKPrimitive $child): void
    {
        $this->tellExpansion();
    }

    private function put(TKPrimitive $child): void
    {
        $this->stack->addWidget($child->native(), 0, 0, 1, 1, self::alignmentOf($child));
    }

    /**
     * Ignored while it fills both ways; once it does not, the child's own applyFill() sets
     * its policy back (it runs after childLayoutChanged()).
     */
    private function sizeChild(TKPrimitive $child): void
    {
        if (! $this->sizesOverlay($child)) {
            $child->native()->setSizePolicy(Policy::IGNORED, Policy::IGNORED);
        }
    }

    /**
     * Back to front: each raised in turn ends above the ones before it.
     */
    private function restack(): void
    {
        foreach ($this->children() as $child) {
            $child->native()->raise();
        }
    }
}

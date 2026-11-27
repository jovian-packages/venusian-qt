<?php

namespace Jovian\Toolkits\Qt\UI;

use QWidget;
use QGridLayout;
use QHBoxLayout;
use QSpacerItem;
use QSizePolicy\Policy;
use Surface\Toolkits\UI\TKOverlay;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Contracts\Toolkits\UI\Insets;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;
use Jovian\Toolkits\Qt\UI\Concerns\QtHeldMargin;
use Jovian\Toolkits\Qt\UI\Concerns\PlacesInSlots;

/**
 * A QWidget with a QGridLayout holding every child in its one cell, each with its alignment;
 * padding is the layout's margins. The first child is at the back: the widgets are raised in
 * the children's order. A child that fills both ways has Qt's IGNORED size policy, so it
 * takes the cell's size and gives the overlay none; the others give it the largest of theirs.
 * A child with a margin is in the cell in a holder whose contents margins are that margin,
 * the child in it with its alignment (QtHeldMargin); a kind that keeps its own margin
 * (QtSheetMargin) is in the cell itself.
 */
class QtOverlay extends TKOverlay
{
    use QtNative;
    use PlacesInSlots;
    use QtHeldMargin;

    /** @var array<string, QHBoxLayout> the holder of each child with a margin, by its uuid */
    private array $holders = [];

    private ?QGridLayout $stack = null;

    /** Its preferred size while it fills up to a maximum (holdCeiling()). */
    private ?QSpacerItem $ceiling = null;

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
        $this->ceiling = new QSpacerItem(0, 0, Policy::MINIMUM, Policy::MINIMUM);
        $this->stack->addItem($this->ceiling, 0, 0);
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
        $this->take($child);
        $this->tellExpansion();
    }

    protected function reorderNative(TKPrimitive $child, int $index): void
    {
        $this->restack();
    }

    /**
     * Filling with a maximum, the spacer in the cell prefers that maximum, so the layout's
     * size hint is, and a parent that centres this overlay gives it its room up to the
     * maximum. The spacer shrinks to nothing (PREFERRED); 0 × 0 MINIMUM is no spacer at all.
     */
    protected function holdCeiling(): void
    {
        if (is_null($this->ceiling)) {
            return;
        }

        [$maxWidth, $maxHeight] = $this->maximumSize();
        [$fillsAcross, $fillsDown] = $this->fills();
        $width = $fillsAcross && $maxWidth > 0 ? $maxWidth : 0;
        $height = $fillsDown && $maxHeight > 0 ? $maxHeight : 0;
        $this->ceiling->changeSize($width, $height, $width > 0 ? Policy::PREFERRED : Policy::MINIMUM, $height > 0 ? Policy::PREFERRED : Policy::MINIMUM);
        $this->stack->invalidate();
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
        $this->take($child);
        $this->put($child);
        $this->sizeChild($child);
        $this->restack();
        $this->tellExpansion();
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
        $this->tellExpansion();
    }

    /**
     * Into the cell: itself, or in a holder of its margin that fills the cell.
     */
    private function put(TKPrimitive $child): void
    {
        if (self::heldMarginOf($child)->isZero()) {
            $this->stack->addWidget($child->native(), 0, 0, 1, 1, self::alignmentOf($child));

            return;
        }

        $holder = self::marginHolder($child, self::alignmentOf($child));
        $this->stack->addLayout($holder, 0, 0, 1, 1, 0);
        $this->holders[$child->uuid()] = $holder;
    }

    /**
     * Out of the cell, and out of its holder when it has one (the holder goes with it).
     */
    private function take(TKPrimitive $child): void
    {
        $holder = $this->holders[$child->uuid()] ?? null;
        if (is_null($holder)) {
            $this->stack->removeWidget($child->native());

            return;
        }

        $this->stack->removeItem($holder);
        $holder->removeWidget($child->native());
        unset($this->holders[$child->uuid()]);
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

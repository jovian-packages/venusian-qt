<?php

namespace Jovian\Toolkits\Qt\UI;

use QWidget;
use Surface\Toolkits\ToolkitException;
use Surface\Toolkits\UI\TKFixed;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Contracts\Toolkits\UI\Placement;
use Surface\Contracts\Toolkits\UI\TKPrimitive as ChildContract;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;
use Jovian\Toolkits\Qt\UI\Concerns\QtHeldMargin;

/**
 * A QWidget with no layout: each child is a child widget at its frame's geometry (Qt keeps a
 * child at least its minimum size). Its own minimum size covers every frame, since children
 * outside a layout do not size their parent. A child's margin moves its frame in by the left
 * and top margin, and the minimum size reaches past the right and bottom margin (QtHeldMargin:
 * none for a kind that keeps its own).
 */
class QtFixed extends TKFixed
{
    use QtNative;
    use QtHeldMargin;

    protected function bootstrapNative(): void
    {
        $this->adoptNative(new QWidget());
    }

    /**
     * A child widget is hidden until shown, when its parent is shown already.
     */
    protected function attachNative(TKPrimitive $child): void
    {
        $native = $child->native();
        $native->setParent($this->native());
        $this->setFrame($child, $child->placement());
        if ($child->isVisible()) {
            $native->show();
        }
        $this->settleExtent();
    }

    /**
     * The child's own release takes it off this widget.
     */
    protected function detachNative(TKPrimitive $child): void
    {
        $this->settleExtent();
    }

    protected function applyFrame(ChildContract $child, Placement $frame): void
    {
        $this->setFrame($child, $frame);
        $this->settleExtent();
    }

    /**
     * The child's margin changed: its frame moves with it.
     */
    protected function childLayoutChanged(TKPrimitive $child): void
    {
        $this->setFrame($child, $this->frameOf($child));
        $this->settleExtent();
    }

    protected function applyMinSize(int $width, int $height): void
    {
        $this->settleExtent();
    }

    /**
     * Never reached: only a column or row lets a child move (TKPrimitive::moveTo refuses here).
     *
     * @throws ToolkitException
     */
    protected function reorderNative(TKPrimitive $child, int $index): void
    {
        throw new ToolkitException("'{$this->path()}' keeps no order: its children are placed by frame.");
    }

    /**
     * The minimum size: the larger of minSize() and what reaches the furthest frame.
     */
    private function settleExtent(): void
    {
        [$width, $height] = $this->minimumSize();
        foreach ($this->children() as $child) {
            $frame = $this->frameOf($child);
            $margin = self::heldMarginOf($child);
            $width = max($width, $frame->x + $margin->left + $frame->width + $margin->right);
            $height = max($height, $frame->y + $margin->top + $frame->height + $margin->bottom);
        }

        $this->native()->setMinimumSize($width, $height);
    }

    /**
     * The child's widget at $frame, moved in by its left and top margin.
     */
    private function setFrame(ChildContract $child, Placement $frame): void
    {
        $margin = $child instanceof TKPrimitive ? self::heldMarginOf($child) : null;
        $child->native()->setGeometry($frame->x + ($margin?->left ?? 0), $frame->y + ($margin?->top ?? 0), $frame->width, $frame->height);
    }
}

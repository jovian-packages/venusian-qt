<?php

namespace Jovian\Toolkits\Qt\UI;

use QWidget;
use Surface\Toolkits\ToolkitException;
use Surface\Toolkits\UI\TKFixed;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Contracts\Toolkits\UI\Placement;
use Surface\Contracts\Toolkits\UI\TKPrimitive as ChildContract;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * A QWidget with no layout: each child is a child widget at its frame's geometry (Qt keeps a
 * child at least its minimum size). Its own minimum size covers every frame, since children
 * outside a layout do not size their parent.
 */
class QtFixed extends TKFixed
{
    use QtNative;

    protected function bootstrapNative(): void
    {
        $this->adoptNative(new QWidget());
    }

    /**
     * A child widget is hidden until shown, when its parent is shown already.
     */
    protected function attachNative(TKPrimitive $child): void
    {
        $frame = $child->placement();
        $native = $child->native();
        $native->setParent($this->native());
        $native->setGeometry($frame->x, $frame->y, $frame->width, $frame->height);
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
        $child->native()->setGeometry($frame->x, $frame->y, $frame->width, $frame->height);
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
            $width = max($width, $frame->x + $frame->width);
            $height = max($height, $frame->y + $frame->height);
        }

        $this->native()->setMinimumSize($width, $height);
    }
}

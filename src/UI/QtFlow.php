<?php

namespace Jovian\Toolkits\Qt\UI;

use QWidget;
use QEvent;
use QObject;
use QEventFilter;
use QEvent\Type as EventType;
use WeakReference;
use Surface\Toolkits\UI\TKFlow;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Contracts\Toolkits\UI\Insets;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;
use Jovian\Toolkits\Qt\UI\Concerns\QtHeldMargin;

/**
 * A QWidget with no layout: Qt has no wrapping layout for widgets (its documented answer is
 * the Flow Layout example, a QLayout that sets each child's geometry from its size hint), so
 * the children are placed here the same way. Each child sits at its size hint, kept within its
 * minimum and maximum size and within the width of a line, moved in by its left and top margin
 * (QtHeldMargin); the next one goes right of it, or onto the next line where its width and
 * margin no longer fit. Lines start at the left padding; spacing is the gap on a line and
 * between lines. A hidden child takes no room. Placed again on every Resize and LayoutRequest
 * of the widget (a QEventFilter): a child whose hint changed asks its parent for a layout.
 * The widget's minimum height is what its lines need at its width, so a column that holds it
 * grows as it wraps; its minimum width is the widest shown child's box plus the left and right
 * padding, so no child is cut and a flow without fill is never zero wide. Each is kept with
 * minSize() (the larger of the two).
 */
class QtFlow extends TKFlow
{
    use QtNative {
        releaseNative as private releaseWidget;
    }
    use QtHeldMargin;

    /** The filter that places the children on Resize and LayoutRequest. */
    private ?QEventFilter $placing = null;

    /** Set while the children are being placed: setting a size on them is not a new request. */
    private bool $placed_now = false;

    /** The height the lines need at the width last placed for. */
    private int $needed = 0;

    /** The width of the widest shown child's box plus the padding: no child is cut. */
    private int $widest = 0;

    protected function bootstrapNative(): void
    {
        $this->adoptNative(new QWidget());

        $self = WeakReference::create($this);
        $this->placing = new QEventFilter(static function (QObject $watched, QEvent $event) use ($self): bool {
            if (in_array($event->type(), [EventType::RESIZE, EventType::LAYOUT_REQUEST], true)) {
                $self->get()?->placeChildren();
            }

            return false;
        });
        $this->native()->installEventFilter($this->placing);
    }

    protected function releaseNative(): void
    {
        if (! is_null($this->placing)) {
            $this->native?->removeEventFilter($this->placing);
            $this->placing = null;
        }

        $this->releaseWidget();
    }

    /**
     * A child widget is hidden until shown, when its parent is shown already.
     */
    protected function attachNative(TKPrimitive $child): void
    {
        $native = $child->native();
        $native->setParent($this->native());
        $this->placeChildren();
        if ($child->isVisible()) {
            $native->show();
        }
    }

    /**
     * The child's own release takes it off this widget.
     */
    protected function detachNative(TKPrimitive $child): void
    {
        $this->placeChildren();
    }

    protected function reorderNative(TKPrimitive $child, int $index): void
    {
        $this->placeChildren();
    }

    protected function applySpacing(int $spacing): void
    {
        $this->placeChildren();
    }

    protected function applyPadding(Insets $padding): void
    {
        $this->placeChildren();
    }

    /**
     * The child's margin changed.
     */
    protected function childLayoutChanged(TKPrimitive $child): void
    {
        $this->placeChildren();
    }

    protected function childVisibilityChanged(TKPrimitive $child): void
    {
        $this->placeChildren();
    }

    /**
     * The larger of minSize() and what the children need, each way.
     */
    protected function applyMinSize(int $width, int $height): void
    {
        $this->native()->setMinimumSize(max($width, $this->widest), max($height, $this->needed));
    }

    /**
     * Every shown child at its place, in display order, then the minimum height.
     */
    private function placeChildren(): void
    {
        if ($this->placed_now || is_null($this->native)) {
            return;
        }
        $this->placed_now = true;

        $padding = $this->padding();
        $left = $padding->left;
        $right = $this->native->width() - $padding->right;
        $x = $left;
        $y = $padding->top;
        $line = 0;
        $placed = false;
        $widest = 0;

        foreach ($this->children() as $child) {
            if (! $child->isVisible()) {
                continue;
            }

            $native = $child->native();
            $margin = self::heldMarginOf($child);
            $hint = $native->sizeHint();
            $width = max($hint->width(), 0);
            $height = max($hint->height(), 0);
            $width = min(max($width, $native->minimumWidth()), $native->maximumWidth());
            $height = min(max($height, $native->minimumHeight()), $native->maximumHeight());
            $widest = max($widest, $margin->left + $width + $margin->right);
            $width = max($native->minimumWidth(), min($width, $right - $left - $margin->left - $margin->right));
            $across = $margin->left + $width + $margin->right;

            if ($x > $left && $x + $across > $right) {
                $x = $left;
                $y += $line + $this->spacing();
                $line = 0;
            }

            $native->setGeometry($x + $margin->left, $y + $margin->top, $width, $height);
            $x += $across + $this->spacing();
            $line = max($line, $margin->top + $height + $margin->bottom);
            $placed = true;
        }

        $this->needed = ($placed ? $y + $line : $y) + $padding->bottom;
        $this->widest = $widest + $padding->left + $padding->right;
        [$minimum_width, $minimum_height] = $this->minimumSize();
        $this->native->setMinimumSize(max($minimum_width, $this->widest), max($minimum_height, $this->needed));
        $this->placed_now = false;
    }
}

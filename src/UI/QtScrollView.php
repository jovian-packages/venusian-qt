<?php

namespace Jovian\Toolkits\Qt\UI;

use QObject;
use QWidget;
use QScrollArea;
use QHBoxLayout;
use WeakReference;
use Surface\Contracts\Toolkits\Styling\ScrollPosition;
use QSizePolicy\Policy;
use Qt\ScrollBarPolicy;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Toolkits\UI\TKScrollView;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;
use Jovian\Toolkits\Qt\UI\Concerns\QtHeldMargin;

/**
 * A QScrollArea whose widget follows the viewport. Transparent, as a scroll view is on the other
 * toolkits: the viewport fills no background, nor does the widget it holds (setWidget() turns
 * that on), so what is behind shows through; a background set on the scroll view is its own
 * style sheet's, painted behind them. The area's widget is a holder with the content in it, in
 * from the content's margin (QtHeldMargin).
 */
class QtScrollView extends TKScrollView
{
    use QtNative;
    use QtHeldMargin;

    /** The content's holder widget and its layout; null before the content comes. */
    private ?QHBoxLayout $holder = null;

    protected function bootstrapNative(): void
    {
        $area = new QScrollArea();
        $area->setWidgetResizable(true);
        $area->viewport()->setAutoFillBackground(false);
        $this->adoptNative($area);
        $self = WeakReference::create($this);
        foreach ([$area->verticalScrollBar(), $area->horizontalScrollBar()] as $bar) {
            QObject::connect($bar, 'valueChanged(int)', static function () use ($self): void {
                $self->get()?->scrolled();
            });
        }
        $this->restyleBox();
        $this->applyScrollbars($this->scrollsAcross(), $this->scrollsDown());
    }

    /**
     * Its frame is a panel's; the others draw none. A border set takes its place.
     */
    protected function ownBorder(): ?string
    {
        return 'none';
    }

    /**
     * The content in a new holder, which becomes the area's widget (the area deletes the one
     * before).
     */
    protected function attachNative(TKPrimitive $child): void
    {
        [$widget, $this->holder] = self::marginWidget($child);
        $this->areaNative()->setWidget($widget);
        $widget->setAutoFillBackground(false);
        $child->native()->setAutoFillBackground(false);
        if ($child->isVisible()) {
            $child->native()->show();
        }
        $this->tellExpansion();
    }

    /** The child's own release takes it off the area. */
    protected function detachNative(TKPrimitive $child): void
    {
        $this->tellExpansion();
    }

    protected function applyScrollbars(bool $horizontal, bool $vertical): void
    {
        $area = $this->areaNative();
        $area->setHorizontalScrollBarPolicy($horizontal ? ScrollBarPolicy::AS_NEEDED : ScrollBarPolicy::ALWAYS_OFF);
        $area->setVerticalScrollBarPolicy($vertical ? ScrollBarPolicy::AS_NEEDED : ScrollBarPolicy::ALWAYS_OFF);
    }

    /** Each bar's value, page and range: the range ends a page short of the content. */
    protected function nativeScrollPosition(): ScrollPosition
    {
        $horizontal = $this->areaNative()->horizontalScrollBar();
        $vertical = $this->areaNative()->verticalScrollBar();

        return ScrollPosition::of(
            $horizontal->value(), $horizontal->pageStep(), $horizontal->maximum() + $horizontal->pageStep(),
            $vertical->value(), $vertical->pageStep(), $vertical->maximum() + $vertical->pageStep(),
        );
    }

    /**
     * The holder keeps the content's margin as it is now.
     */
    protected function childLayoutChanged(TKPrimitive $child): void
    {
        if (! is_null($this->holder)) {
            self::holdMargin($this->holder, $child);
        }
        $this->tellExpansion();
    }

    protected function childVisibilityChanged(TKPrimitive $child): void
    {
        if (! is_null($this->holder)) {
            self::holdMargin($this->holder, $child);
        }
        $this->tellExpansion();
    }

    /**
     * @return array{Policy, Policy}
     */
    protected function naturalSizePolicy(): array
    {
        return [Policy::PREFERRED, Policy::PREFERRED];
    }

    private function areaNative(): QScrollArea
    {
        /** @var QScrollArea */
        return $this->native();
    }
}

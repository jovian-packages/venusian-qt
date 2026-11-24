<?php

namespace Jovian\Toolkits\Qt\UI;

use QEvent;
use QObject;
use QSplitter;
use QEventFilter;
use WeakReference;
use Qt\Orientation;
use QEvent\Type as EventType;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Toolkits\UI\TKSplitView;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * A QSplitter. splitterMoved() comes from the user's drag alone; a resize puts the divider
 * back at its fraction.
 */
class QtSplitView extends TKSplitView
{
    use QtNative;

    private ?QEventFilter $resizes = null;

    protected function bootstrapNative(): void
    {
        $split = new QSplitter($this->isHorizontal() ? Orientation::HORIZONTAL : Orientation::VERTICAL);
        $split->setChildrenCollapsible(false);
        $split->setOpaqueResize(true);
        $this->adoptNative($split);

        $self = WeakReference::create($this);
        QObject::connect($split, 'splitterMoved(int,int)', static function () use ($self): void {
            $self->get()?->moved();
        });
        $this->resizes = new QEventFilter(static function (QObject $watched, QEvent $event) use ($self): bool {
            if ($event->type() === EventType::RESIZE) {
                $self->get()?->resized();
            }

            return false;
        });
        $split->installEventFilter($this->resizes);
    }

    protected function attachNative(TKPrimitive $child): void
    {
        $this->splitNative()->addWidget($child->native());
        if ($child->isVisible()) {
            $child->native()->show();
        }
        $this->tellExpansion();
    }

    /** The child's own release takes it off the splitter. */
    protected function detachNative(TKPrimitive $child): void
    {
        $this->tellExpansion();
    }

    protected function reorderNative(TKPrimitive $child, int $index): void
    {
        $this->splitNative()->insertWidget($index, $child->native());
    }

    protected function applyPosition(float $position): void
    {
        $room = $this->room();
        $split = $this->splitNative();
        if ($room <= 0 || $split->count() !== 2) {
            return;
        }
        $first = (int) round($position * $room);
        $split->setSizes([$first, $room - $first]);
    }

    protected function childLayoutChanged(TKPrimitive $child): void
    {
        $this->tellExpansion();
    }

    private function resized(): void
    {
        if (! $this->isRemoved() && ! $this->isWriting() && $this->hasDivider()) {
            $this->write(fn () => $this->applyPosition($this->position()));
        }
    }

    private function moved(): void
    {
        $sizes = $this->splitNative()->sizes();
        if (count($sizes) === 2 && array_sum($sizes) > 0) {
            $this->dragged($sizes[0] / array_sum($sizes));
        }
    }

    private function room(): int
    {
        $split = $this->splitNative();

        return ($this->isHorizontal() ? $split->width() : $split->height()) - $split->handleWidth();
    }

    private function splitNative(): QSplitter
    {
        /** @var QSplitter */
        return $this->native();
    }
}

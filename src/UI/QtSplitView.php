<?php

namespace Jovian\Toolkits\Qt\UI;

use QEvent;
use QObject;
use QWidget;
use QSplitter;
use QHBoxLayout;
use QEventFilter;
use WeakReference;
use Qt\Orientation;
use QEvent\Type as EventType;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Toolkits\UI\TKSplitView;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;
use Jovian\Toolkits\Qt\UI\Concerns\QtHeldMargin;

/**
 * A QSplitter. splitterMoved() comes from the user's drag alone; a resize puts the divider
 * back at its fraction. Each pane is a holder widget with the child in it, in from the
 * child's margin (QtHeldMargin), shown while the child is.
 */
class QtSplitView extends TKSplitView
{
    use QtNative;
    use QtHeldMargin;

    /** @var array<string, array{QWidget, QHBoxLayout}> each child's pane and its layout, by uuid */
    private array $panes = [];

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
        $this->panes[$child->uuid()] = self::marginWidget($child);
        $pane = $this->paneOf($child);
        $this->splitNative()->addWidget($pane);
        if ($child->isVisible()) {
            $child->native()->show();
            $pane->show();
        } else {
            $pane->hide();
        }
        $this->tellExpansion();
    }

    /**
     * The pane goes; the child's own release takes it out of the pane first.
     */
    protected function detachNative(TKPrimitive $child): void
    {
        $this->paneOf($child)->deleteLater();
        unset($this->panes[$child->uuid()]);
        $this->tellExpansion();
    }

    protected function reorderNative(TKPrimitive $child, int $index): void
    {
        $this->splitNative()->insertWidget($index, $this->paneOf($child));
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

    /**
     * The pane keeps the child's margin as it is now.
     */
    protected function childLayoutChanged(TKPrimitive $child): void
    {
        self::holdMargin($this->panes[$child->uuid()][1], $child);
        $this->tellExpansion();
    }

    /**
     * A hidden child's pane is hidden with it: the splitter gives it no room.
     */
    protected function childVisibilityChanged(TKPrimitive $child): void
    {
        self::holdMargin($this->panes[$child->uuid()][1], $child);
        $this->paneOf($child)->setVisible($child->isVisible());
        $this->tellExpansion();
    }

    private function paneOf(TKPrimitive $child): QWidget
    {
        return $this->panes[$child->uuid()][0];
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

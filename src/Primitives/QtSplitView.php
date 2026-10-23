<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtContainer;
use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QEvent;
use QEvent\Type as EventType;
use QEventFilter;
use QObject;
use QSizePolicy\Policy;
use QSplitter;
use Qt\Orientation;
use Surface\Contracts\Windows\Mail\View\SplitMoved;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\TKPrimitive as Child;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKSplitView;
use WeakReference;

/**
 * A QSplitter whose widgets are the panes, in the order added; Qt gives a hidden pane no size
 * and hides its handle. Positions are the visible panes' ends over the splitter's length, from
 * sizes() and the handle width. Positions set before the splitter has a length wait for its
 * first Resize, which otherwise shares the length equally.
 *
 * splitterMoved fires only for a handle the user moves (opaque resize: during the drag), so it
 * marks a drag; a filter on each handle notes the positions at the press and, at that drag's
 * button release, posts SplitMoved if they moved. A window resize and setSizes() move no handle
 * and post nothing.
 */
class QtSplitView extends TKSplitView implements QtContainer
{
    use QtPrimitive;

    /**
     * Fractions set before the splitter had a length.
     * @var list<float>|null
     */
    protected ?array $pending_positions = null;

    /** The splitter has had its first Resize with a length. */
    protected bool $laid_out = false;

    /** A handle moved under the user's drag since its press. */
    protected bool $dragging = false;

    /**
     * The positions at a handle's press, until its release.
     * @var list<float>|null
     */
    protected ?array $drag_from = null;

    /** Sees each handle's button release. */
    protected QEventFilter $handle_filter;

    /** Sees the splitter's first Resize with a length. */
    protected QEventFilter $layout_filter;


    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, bool $horizontal)
    {
        parent::__construct($name, $window, $parent, $placement, $horizontal);
        $splitter = $this->adoptNative(new QSplitter($horizontal ? Orientation::HORIZONTAL : Orientation::VERTICAL));
        $splitter->setOpaqueResize(true);
        $splitter->setChildrenCollapsible(false);

        QObject::connect($splitter, 'splitterMoved(int,int)', function (int $position, int $index): void {
            if ($this->handling()) {
                $this->dragging = true;
            }
        });

        // The filters hold their callables where PHP's collector cannot see them: a weak reference keeps them from holding the split.
        $self = WeakReference::create($this);
        $this->handle_filter = new QEventFilter(static function (QObject $watched, QEvent $event) use ($self): bool {
            $self->get()?->handleEvent($event);

            return false;
        });
        $this->layout_filter = new QEventFilter(static function (QObject $watched, QEvent $event) use ($self): bool {
            $self->get()?->layoutEvent($event);

            return false;
        });
        $splitter->installEventFilter($this->layout_filter);
    }

    /**
     * A handle's press notes the positions; its release posts SplitMoved if splitterMoved marked a drag.
     *
     * @param QEvent $event
     * @return void
     */
    protected function handleEvent(QEvent $event): void
    {
        $type = $event->type();
        if ($type === EventType::MOUSE_BUTTON_PRESS) {
            $this->dragging = false;
            $this->drag_from = $this->nativePositions();
        } elseif ($type === EventType::MOUSE_BUTTON_RELEASE && $this->dragging && ! is_null($this->drag_from)) {
            $this->dragging = false;
            $this->releasedDrag($this->drag_from);
            $this->drag_from = null;
        }
    }

    /**
     * The split's first resize with a length places the positions set before it, or equal shares.
     *
     * @param QEvent $event
     * @return void
     */
    protected function layoutEvent(QEvent $event): void
    {
        if ($event->type() === EventType::RESIZE && ! $this->laid_out && ! $this->removed && $this->length() > 0) {
            $this->laid_out = true;
            $dividers = $this->dividers();
            $pending = $this->pending_positions;
            $this->pending_positions = null;
            if ($dividers > 0) {
                $this->applyPositions(! is_null($pending) && count($pending) === $dividers ? $pending : self::equalShares($dividers));
            }
        }
    }

    public function native(): QSplitter
    {
        return $this->native;
    }

    /**
     * The splitter sizes its panes; alignment has no slot to act in.
     * @param Child&QtNative $child
     * @return void
     */
    public function placeChild(Child&QtNative $child): void {}

    protected function naturalPolicy(): array
    {
        return [Policy::EXPANDING, Policy::EXPANDING];
    }

    protected function insertNative(Child $child): void
    {
        $this->native()->addWidget(self::nativeOf($child));
        $this->watchHandles();
    }

    protected function nativePositions(): array
    {
        $sizes = $this->visibleSizes();
        $count = count($sizes) - 1;
        if ($count < 1) {
            return [];
        }

        $length = $this->length();
        if ($length <= 0 || ! $this->laid_out) {
            return ! is_null($this->pending_positions) && count($this->pending_positions) === $count
                ? $this->pending_positions
                : self::equalShares($count);
        }

        $handle = $this->native()->handleWidth();
        $positions = [];
        $start = 0;
        foreach (array_slice($sizes, 0, -1) as $size) {
            $positions[] = ($start + $size) / $length;
            $start += $size + $handle;
        }

        return $positions;
    }

    protected function applyPositions(array $positions): void
    {
        $length = $this->length();
        if ($length <= 0 || ! $this->laid_out) {
            $this->pending_positions = $positions;

            return;
        }

        $handle = $this->native()->handleWidth();
        $sizes = array_fill(0, $this->native()->count(), 0);
        $indexes = $this->visibleIndexes();
        $start = 0;
        foreach ($indexes as $k => $index) {
            $end = isset($positions[$k]) ? (int) round($positions[$k] * $length) : $length;
            $sizes[$index] = max(0, $end - $start);
            $start = $end + $handle;
        }
        $this->applying(fn () => $this->native()->setSizes($sizes));
    }

    /**
     * A handle drag ended: post SplitMoved if a divider moved by more than half a pixel. A handle
     * moved by the mouse emits splitterMoved on every move event, its position changed or not.
     *
     * @param list<float> $from
     * @return void
     */
    protected function releasedDrag(array $from): void
    {
        if ($this->removed) {
            return;
        }

        $to = $this->nativePositions();
        $length = $this->length();
        $moved = count($to) !== count($from);
        foreach ($to as $i => $position) {
            $moved = $moved || abs($position - $from[$i]) * $length > 0.5;
        }
        if ($moved) {
            $this->session()->post(new SplitMoved($this->window->name(), $this->path(), $this->uuid, $to));
        }
    }

    /**
     * The filter on every handle the splitter has: one appears with each pane after the first.
     * Installing a filter a handle already has moves it to the front, so every call covers them all.
     * @return void
     */
    protected function watchHandles(): void
    {
        for ($i = 1; $i < $this->native()->count(); $i++) {
            $this->native()->handle($i)?->installEventFilter($this->handle_filter);
        }
    }

    /**
     * The splitter indexes of the visible panes, in order.
     * @return list<int>
     */
    protected function visibleIndexes(): array
    {
        $indexes = [];
        foreach ($this->children as $child) {
            $index = $this->native()->indexOf(self::nativeOf($child));
            if ($child->isVisible() && $index >= 0) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    /**
     * The visible panes' sizes along the splitter, in order.
     * @return list<int>
     */
    protected function visibleSizes(): array
    {
        $sizes = $this->native()->sizes();

        return array_map(fn (int $index): int => $sizes[$index] ?? 0, $this->visibleIndexes());
    }

    protected function length(): int
    {
        return $this->horizontal ? $this->native()->width() : $this->native()->height();
    }

    /**
     * @param int $dividers
     * @return list<float>
     */
    protected static function equalShares(int $dividers): array
    {
        return array_map(fn (int $i): float => ($i + 1) / ($dividers + 1), range(0, $dividers - 1));
    }
}

<?php

namespace Jovian\Toolkits\Qt\Contracts\Primitives;

use QWidget;

/**
 * Every Qt primitive: the widget it drives, and where it sits in its parent's slot.
 */
interface QtNative
{
    /**
     * The native widget. remove() unparents it and schedules its deletion: once the next pump has
     * run that deferred delete, calls on it throw QtException.
     * @return QWidget
     */
    public function native(): QWidget;

    /**
     * The primitive's fill() per axis, for its container's stretch factors.
     * @return array{bool, bool} horizontal, vertical
     */
    public function fills(): array;

    /**
     * The primitive's align() as Qt::Alignment flags; 0 when both axes FILL.
     * @return int
     */
    public function qtAlignment(): int;
}

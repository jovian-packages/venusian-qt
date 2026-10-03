<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtContainer;
use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QWidget;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\TKPrimitive as Child;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKFixed;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A QWidget with no layout: children are parented to it and placed by geometry.
 */
class QtFixed extends TKFixed implements QtContainer
{
    use QtPrimitive;

    /**
     * The minimum asked for with minSize(); the native's minimum also covers every frame,
     * since a widget without a layout has no size hint to keep it open beside a filling sibling.
     * @var array{int, int}
     */
    protected array $asked_minimum = [0, 0];

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement)
    {
        parent::__construct($name, $window, $parent, $placement);
        $this->adoptNative(new QWidget());
    }

    /**
     * Frames place children here; alignment has no slot to act in.
     * @param Child&QtNative $child
     * @return void
     */
    public function placeChild(Child&QtNative $child): void {}

    protected function insertNative(Child $child): void
    {
        $native = self::nativeOf($child);
        $frame = $this->frameOf($child);
        $native->setParent($this->native);
        $native->setGeometry($frame->x, $frame->y, $frame->width, $frame->height);
        // A child given a parent that is already showing stays hidden until shown; layouts do this for their children.
        $native->show();
        $this->coverFrames();
    }

    public function forgetChild(Child $child): void
    {
        parent::forgetChild($child);
        $this->whileNativeAlive(fn () => $this->coverFrames());
    }

    protected function applyMove(Child $child, int $x, int $y): void
    {
        self::nativeOf($child)->move($x, $y);
        $this->coverFrames();
    }

    protected function applyResize(Child $child, int $width, int $height): void
    {
        self::nativeOf($child)->resize($width, $height);
        $this->coverFrames();
    }

    protected function applyMinSize(int $width, int $height): void
    {
        $this->asked_minimum = [$width, $height];
        $this->coverFrames();
    }

    protected function coverFrames(): void
    {
        [$width, $height] = $this->asked_minimum;
        foreach ($this->frames as $frame) {
            $width = max($width, $frame->x + $frame->width);
            $height = max($height, $frame->y + $frame->height);
        }
        $this->native->setMinimumSize($width, $height);
    }
}

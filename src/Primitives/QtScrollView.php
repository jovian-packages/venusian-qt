<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtContainer;
use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QScrollArea;
use QSizePolicy\Policy;
use Qt\ScrollBarPolicy;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\TKPrimitive as Child;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKScrollView;

/**
 * A QScrollArea whose one container resizes with the viewport and scrolls past it.
 */
class QtScrollView extends TKScrollView implements QtContainer
{
    use QtPrimitive;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement)
    {
        parent::__construct($name, $window, $parent, $placement);
        $area = $this->adoptNative(new QScrollArea());
        $area->setWidgetResizable(true);
    }

    /**
     * The one container follows the viewport (widgetResizable); alignment has no slot to act in.
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
        $this->native()->setWidget(self::nativeOf($child));
    }

    protected function applyScrollbars(bool $horizontal, bool $vertical): void
    {
        $this->native()->setHorizontalScrollBarPolicy($horizontal ? ScrollBarPolicy::AS_NEEDED : ScrollBarPolicy::ALWAYS_OFF);
        $this->native()->setVerticalScrollBarPolicy($vertical ? ScrollBarPolicy::AS_NEEDED : ScrollBarPolicy::ALWAYS_OFF);
    }

    public function native(): QScrollArea
    {
        return $this->native;
    }
}

<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QFrame;
use QFrame\Shadow;
use QFrame\Shape;
use QSizePolicy\Policy;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKSeparator;

class QtSeparator extends TKSeparator implements QtNative
{
    use QtPrimitive;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, bool $horizontal)
    {
        parent::__construct($name, $window, $parent, $placement, $horizontal);
        $frame = $this->adoptNative(new QFrame());
        $frame->setFrameShape($horizontal ? Shape::H_LINE : Shape::V_LINE);
        $frame->setFrameShadow(Shadow::SUNKEN);
    }

    public function native(): QFrame
    {
        return $this->native;
    }

    /**
     * What QFrame sets for a line shape.
     */
    protected function naturalPolicy(): array
    {
        return $this->horizontal ? [Policy::MINIMUM, Policy::FIXED] : [Policy::FIXED, Policy::MINIMUM];
    }
}

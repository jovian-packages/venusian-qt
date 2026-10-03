<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtContainer;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtStack;
use QHBoxLayout;
use QWidget;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKRow;
use Surface\Windows\Primitives\TKPrimitiveGroup;

class QtRow extends TKRow implements QtContainer
{
    use QtPrimitive;
    use QtStack;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, int $spacing, int $padding)
    {
        parent::__construct($name, $window, $parent, $placement, $spacing, $padding);
        $this->buildStack(new QHBoxLayout($this->adoptNative(new QWidget())));
    }
}

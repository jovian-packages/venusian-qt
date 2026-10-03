<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtContainer;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtStack;
use QVBoxLayout;
use QWidget;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKColumn;
use Surface\Windows\Primitives\TKPrimitiveGroup;

class QtColumn extends TKColumn implements QtContainer
{
    use QtPrimitive;
    use QtStack;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, int $spacing, int $padding)
    {
        parent::__construct($name, $window, $parent, $placement, $spacing, $padding);
        $this->buildStack(new QVBoxLayout($this->adoptNative(new QWidget())));
    }
}

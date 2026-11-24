<?php

namespace Jovian\Toolkits\Qt\UI;

use QWidget;
use QBoxLayout;
use QHBoxLayout;
use Surface\Toolkits\UI\TKRow;
use Surface\Toolkits\UI\TKPrimitive;
use Jovian\Toolkits\Qt\UI\Concerns\QtBoxChildren;

/**
 * A QWidget with a QHBoxLayout.
 */
class QtRow extends TKRow
{
    use QtBoxChildren;

    protected function newLayout(QWidget $widget): QBoxLayout
    {
        return new QHBoxLayout($widget);
    }

    protected function fillsAlong(TKPrimitive $child): bool
    {
        return $child->expands(true);
    }
}

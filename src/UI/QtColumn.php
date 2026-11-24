<?php

namespace Jovian\Toolkits\Qt\UI;

use QWidget;
use QBoxLayout;
use QVBoxLayout;
use Surface\Toolkits\UI\TKColumn;
use Surface\Toolkits\UI\TKPrimitive;
use Jovian\Toolkits\Qt\UI\Concerns\QtBoxChildren;

/**
 * A QWidget with a QVBoxLayout.
 */
class QtColumn extends TKColumn
{
    use QtBoxChildren;

    protected function newLayout(QWidget $widget): QBoxLayout
    {
        return new QVBoxLayout($widget);
    }

    protected function fillsAlong(TKPrimitive $child): bool
    {
        return $child->expands(false);
    }
}
